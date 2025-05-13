<?php

// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

namespace app\common\repositories\store\product;

use app\common\repositories\BaseRepository;
use app\common\repositories\system\attachment\AttachmentCategoryRepository;
use app\common\repositories\system\attachment\AttachmentRepository;
use app\common\repositories\system\merchant\MerchantRepository;
use crmeb\services\CopyProductService;
use crmeb\services\CrmebServeServices;
use crmeb\services\DownloadImageService;
use Exception;
use think\exception\ValidateException;
use app\common\dao\store\product\ProductCopyDao;
use think\facade\Cache;
use think\facade\Db;

class ProductCopyRepository extends BaseRepository
{
    protected $host = ['taobao', 'tmall', 'jd', 'pinduoduo', 'suning', 'yangkeduo','1688'];
    protected $AttachmentCategoryName = '远程下载';
    protected $dao;
    protected $updateImage = ['image', 'slider_image'];
    protected $AttachmentCategoryPath = 'copy';
    /**
     * ProductRepository constructor.
     * @param dao $dao
     */
    public function __construct(ProductCopyDao $dao)
    {
        $this->dao = $dao;
    }

    public function getProduct($url,$merId)
    {
        $key = $merId.'_url_'.json_encode($url);
        if ($result= Cache::get($key)) return json_decode($result,true);

        if (systemConfig('copy_product_status') == 1) {
            $resultData = $this->useApi($url);
        } else {
            $resultData['data'] = app()->make(CrmebServeServices::class)->copy()->goods($url);
            $resultData['status'] = true;
        }
        if ($resultData['status'] && $resultData['status']) {
            $result = $this->getParamsData($resultData['data']);
            Cache::set($key,json_encode($result), 60 * 60 * 2);
        } else {
            if (isset($resultData['msg'])) throw  new ValidateException('接口错误信息:'.$resultData['msg']);
            throw  new ValidateException('采集失败，请更换链接重试！');
        }
        $this->add(['type' => 'copy', 'num' => -1, 'info' => $url , 'mer_id' => $merId, 'message' => '采集商品',], $merId);
        return $result;
    }

    /**
     * TODO 99api采集
     * @param $url
     * @return array
     * @author Qinii
     * @day 2022/11/11
     */
    public function useApi($url)
    {
        $apikey = systemConfig('copy_product_apikey');
        if (!$apikey) throw new ValidateException('请前往平台后台-设置-第三方接口-配置接口密钥');
        $url_arr = parse_url($url);
        if (isset($url_arr['host'])) {
            foreach ($this->host as $name) {
                if (strpos($url_arr['host'], $name) !== false) {
                    $type = $name;
                }
            }
        }
        $type = ($type == 'pinduoduo' || $type == 'yangkeduo') ? 'pdd' : $type;
        try{
            switch ($type) {
                case 'taobao':
                case 'tmall':
                    $params = [];
                    if (isset($url_arr['query']) && $url_arr['query']) {
                        $queryParts = explode('&', $url_arr['query']);
                        foreach ($queryParts as $param) {
                            $item = explode('=', $param);
                            if (isset($item[0]) && $item[1]) $params[$item[0]] = $item[1];
                        }
                    }
                    $id = $params['id'] ?? '';
                    break;
                case 'jd':
                    $params = [];
                    if (isset($url_arr['path']) && $url_arr['path']) {
                        $path = str_replace('.html', '', $url_arr['path']);
                        $params = explode('/', $path);
                    }
                    $id = $params[1] ?? '';
                    break;
                case 'pdd':
                    $params = [];
                    if (isset($url_arr['query']) && $url_arr['query']) {
                        $queryParts = explode('&', $url_arr['query']);
                        foreach ($queryParts as $param) {
                            $item = explode('=', $param);
                            if (isset($item[0]) && $item[1]) $params[$item[0]] = $item[1];
                        }
                    }
                    $id = $params['goods_id'] ?? '';
                    break;
                case 'suning':
                    $params = [];
                    if (isset($url_arr['path']) && $url_arr['path']) {
                        $path = str_replace('.html', '', $url_arr['path']);
                        $params = explode('/', $path);
                    }
                    $id = $params[2] ?? '';
                    $shopid = $params[1] ?? '';
                    break;
                case '1688':
                    $params = [];
                    if (isset($url_arr['query']) && $url_arr['query']) {
                        $path = str_replace('.html', '', $url_arr['path']);
                        $params = explode('/', $path);
                    }
                    $id = $params[2] ?? '';
                    $shopid = $params[1] ?? '';
                    $type = 'alibaba';
                    break;

            }
        }catch (Exception $exception){
            throw new ValidateException('url有误');
        }
        $result = CopyProductService::getInfo($type, ['itemid' => $id, 'shopid' => $shopid ?? ''], $apikey);
        return $result;
    }

    /**
     * TODO 整理参数
     * @param $data
     * @return array
     * @author Qinii
     * @day 2022/11/11
     *
     */
    public function getParamsData($data)
    {
        if(!is_array($data['slider_image'])) $data['slider_image'] = json_decode($data['slider_image']);
        $params = ProductRepository::CREATE_PARAMS;
        foreach ($params as $param) {
            if (is_array($param)) {
                $res[$param[0]] = $param[1];
            } else {
                $res[$param] = $data[$param] ?? '';
            }
//            if (in_array($param,$this->updateImage)) {
//                $res[$param] = $this->getImageByUrl($data[$param]);
//            }
        }
        $attr = $data['items'] ?? $data['info']['attr'];
        $value = $data['info']['value'] ?? [];
        $res['spec_type'] = count($attr) ? '1' : '0';
        $res['content'] = $data['description'];
        $res['is_copy'] = 1;
//        $res1['value'] = app()->make(ProductRepository::class)->isFormatAttr($attr,0,$value);
        $res['attr'] = $attr;
        $res['value'] = $value;
        return $res;
    }

    /**
     * TODO 处理需要下载的图片
     * @param $id
     * @param $data
     * @author Qinii
     * @day 2023/4/13
     */
    public function downloadImage($id, $data)
    {
        foreach ($data as $key => $param) {
            if (in_array($key,$this->updateImage)) {
                $res[$key] = $this->getImageByUrl($param, $data['mer_id'] ?? 0, $data['admin_id'] ?? 0);
            }
        }
        $data['content'] = $this->getDescriptionImage($data);
        $make = app()->make(ProductRepository::class);
        try{
            if(!empty($res)){
                if(!empty($res['slider_image'])){
                    $res['slider_image'] = is_array($res['slider_image']) ? implode(',', $res['slider_image']) : '';
                }
                $make->update($id, $res);
            }
            if (!empty($data['content'])) {
                app()->make(ProductContentRepository::class)->clearAttr($id, $data['type'] ?? 0);
                $make->createContent($id, ['content' => $data['content'], 'type' => $data['type'] ?? 0]);
            }
        }catch (Exception $exception) {

        }
    }

    /**
     * TODO 替换详情页的图片地址
     * @param $images
     * @param $html
     * @return mixed|string|string[]|null
     * @author Qinii
     * @day 2022/11/11
     */
    public function getDescriptionImage($data)
    {
        $html = $data['content'];
        preg_match_all('#<img.*?src="([^"]*)"[^>]*>#i', $html, $match);
        if (isset($match[1])) {
            foreach ($match[1] as $item) {
                $uploadValue = $this->getImageByUrl($item, $data['mer_id'] ?? 0, $data['admin_id'] ?? 0);
                //下载成功更新数据库
                if ($uploadValue) {
                    //替换图片
                    $html = str_replace($item, $uploadValue, $html);
                } else {
                    //替换掉没有下载下来的图片
                    $html = preg_replace('#<img.*?src="' . $item . '"*>#i', '', $html);
                }
            }
        }
        return $html;
    }

    /**
     * TODO 根据url下载图片
     * @param $data
     * @return array|mixed|string
     * @author Qinii
     * @day 2022/11/11
     */
    public function getImageByUrl($data, $merId = 0, $adminId = 0)
    {
        if (empty($merId)) {
            $merId = request()->merId();
        }
        if (empty($adminId)) {
            $merId = request()->merAdminId();
        }
        $category = app()->make(AttachmentCategoryRepository::class)->findOrCreate([
            'attachment_category_enname' => $this->AttachmentCategoryPath,
            'attachment_category_name' => $this->AttachmentCategoryName,
            'mer_id' => $merId,
            'pid' => 0,
        ]);
        $make = app()->make(AttachmentRepository::class);
        $serve = app()->make(DownloadImageService::class);
        $type = (int)systemConfig('upload_type') ?: 1;

        if (is_array($data)) {
            foreach ($data as $datum) {
                $arcurl = is_int(strpos($datum, 'http')) ? $datum : 'http://' . ltrim($datum, '\//');
                $image = $serve->downloadImage($arcurl, $this->AttachmentCategoryPath);
                $dir = $type == 1 ? rtrim(systemConfig('site_url'), '/') . $image['path'] : $image['path'];
                $data = [
                    'attachment_category_id' => $category->attachment_category_id,
                    'attachment_name' => $image['name'],
                    'attachment_src' => $dir
                ];
                $make->create($type, $merId, $adminId, $data);
                $res[] = $dir;
            }
        } else {
            $arcurl = is_int(strpos($data, 'http')) ? $data : 'http://' . ltrim($data, '\//');
            $image = $serve->downloadImage($arcurl, $this->AttachmentCategoryPath);
            $dir = $type == 1 ? rtrim(systemConfig('site_url'), '/') . $image['path'] : $image['path'];
            $data = [
                'attachment_category_id' => $category->attachment_category_id,
                'attachment_name' => $image['name'],
                'attachment_src' => $dir
            ];
            $make->create($type, $merId, $adminId, $data);
            $res = $dir;
        }
        return $res;
    }


    /**
     * TODO 添加记录并修改数据
     * @param $data
     * @param $merId
     * @author Qinii
     * @day 2020-08-06
     */
    public function add($data,$merId)
    {
        $make = app()->make(MerchantRepository::class);
        $getOne = $make->get($merId);

        switch ($data['type']) {
            case 'mer_dump':
                //nobreak;
            case 'pay_dump':
                $field = 'export_dump_num';
                break;
            case 'sys':
                //nobreak;
                //nobreak;
            case 'pay_copy':
                //nobreak;
            case 'copy':
                //nobreak;
                $field = 'copy_product_num';
                break;
            default:
                $field = 'copy_product_num';
                break;
        }


        $number = $getOne[$field] + $data['num'];
        $arr = [
            'type'  => $data['type'],
            'num'   => $data['num'],
            'info'   => $data['info']??'' ,
            'mer_id'=> $merId,
            'message' => $data['message'] ?? '',
            'number' => ($number < 0) ? 0 : $number,
        ];
        Db::transaction(function()use($arr,$make,$field){
            $this->dao->create($arr);
            if ($arr['num'] < 0) {
                $make->sumFieldNum($arr['mer_id'],$arr['num'],$field);
            } else {
                $make->addFieldNum($arr['mer_id'],$arr['num'],$field);
            }
        });
    }

    /**
     * TODO 默认赠送复制次数
     * @param $merId
     * @author Qinii
     * @day 2020-08-06
     */
    public function defaulCopyNum($merId)
    {
        if(systemConfig('copy_product_status') && systemConfig('copy_product_defaul')){
            $data = [
                'type' => 'sys',
                'num' => systemConfig('copy_product_defaul'),
                'message' => '赠送次数',
            ];
            $this->add($data,$merId);
        }
    }

    public function getList(array $where,int $page, int $limit)
    {
        $query = $this->dao->search($where)->with([
            'merchant' => function ($query) {
                return $query->field('mer_id,mer_name');
            }
        ]);
        $count = $query->count();
        $list = $query->page($page,$limit)->select();
        return compact('count','list');
    }
}
