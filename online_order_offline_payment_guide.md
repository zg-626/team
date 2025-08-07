# 线上订单选择线下支付方式功能指南

## 功能概述

本功能允许用户在线上下单时选择线下支付方式，用户下单后需要上传支付凭证，管理员审核通过后订单才会生效。这种支付方式适用于银行转账、现金支付等场景。

## 核心特性

### 1. 支付方式扩展
- 新增 `offline` 支付类型
- 支持线上下单，线下支付流程
- 无需生成第三方支付参数

### 2. 支付凭证管理
- 用户可上传支付凭证图片
- 支持多种图片格式
- 自动关联订单信息

### 3. 审核机制
- 管理员可查看支付凭证
- 支持审核通过/拒绝操作
- 完整的审核日志记录

## API 接口说明

### 1. 创建订单接口

**接口地址：** `POST /api/store/order/create`

**请求参数：**
```json
{
  "payType": "offline",
  "cartId": [1, 2, 3],
  "addressId": 1,
  "mark": "备注信息",
  "couponId": 0,
  "useIntegral": 0,
  "shippingType": 1
}
```

**响应示例：**
```json
{
  "status": 200,
  "msg": "订单创建成功",
  "data": {
    "orderId": "202312010001",
    "payPrice": 99.00,
    "message": "订单创建成功，请上传支付凭证"
  }
}
```

### 2. 上传支付凭证接口

**接口地址：** `POST /api/store/order/upload_payment_voucher/{orderId}`

**请求参数：**
```json
{
  "payment_voucher": "支付凭证图片URL"
}
```

**响应示例：**
```json
{
  "status": 200,
  "msg": "支付凭证上传成功，等待审核",
  "data": {
    "orderId": "202312010001",
    "status": "待审核"
  }
}
```

## 核心代码实现

### 1. 支付类型常量定义

```php
// StoreOrderRepository.php
const PAY_TYPE = [
    'balance' => '余额支付',
    'weixin' => '微信支付',
    'routine' => '小程序支付', 
    'h5' => 'H5支付',
    'alipay' => '支付宝支付',
    'alipayQr' => '支付宝扫码支付',
    'weixinQr' => '微信扫码支付',
    'offline' => '线下支付'  // 新增
];
```

### 2. 支付处理逻辑

```php
// StoreOrderRepository.php - pay方法
public function pay($payType, $orderInfo, $payPrice, $successAction, $group = false, $qrcode = false)
{
    // ... 其他支付类型处理
    
    if ($payType == 'offline') {
        return [
            'orderId' => $orderInfo['order_id'],
            'payPrice' => $payPrice,
            'message' => '订单创建成功，请上传支付凭证'
        ];
    }
    
    // ... 其他逻辑
}
```

### 3. 订单创建逻辑优化

```php
// StoreOrder.php - v2CreateOrder方法
if ($payPrice <= 0 && $payType !== 'offline') {
    // 只有非线下支付且金额为0时才自动支付成功
    app()->make(StoreOrderRepository::class)->paySuccess($order);
    return app('json')->success('支付成功', compact('orderId'));
}
```

### 4. 支付凭证上传方法

```php
// StoreOrder.php
public function uploadPaymentVoucher($id)
{
    $data = $this->request->params([
        ['payment_voucher', '']
    ]);
    
    if (empty($data['payment_voucher'])) {
        return app('json')->fail('请上传支付凭证');
    }
    
    $order = app()->make(StoreOrderRepository::class)->get($id);
    if (!$order) {
        return app('json')->fail('订单不存在');
    }
    
    if ($order['paid'] == 1) {
        return app('json')->fail('订单已支付，无需上传凭证');
    }
    
    if ($order['pay_type'] !== 'offline') {
        return app('json')->fail('非线下支付订单，无需上传凭证');
    }
    
    // 更新主订单支付凭证
    app()->make(StoreOrderRepository::class)->update($order['order_id'], [
        'payment_voucher' => $data['payment_voucher']
    ]);
    
    // 更新子订单支付凭证
    app()->make(StoreOrderRepository::class)->getSearch([])
        ->where('group_order_id', $order['order_id'])
        ->update(['payment_voucher' => $data['payment_voucher']]);
    
    return app('json')->success('支付凭证上传成功，等待审核');
}
```

## 管理员审核功能

### 1. 审核表单

管理员可以在后台查看订单的支付凭证，并进行审核操作：

- **支付金额**：显示订单应付金额（只读）
- **支付凭证**：显示用户上传的支付凭证图片（只读）
- **审核状态**：选择通过或拒绝
- **拒绝原因**：当选择拒绝时需要填写原因

### 2. 审核处理

- **审核通过**：调用 `paySuccess` 方法，触发支付成功流程
- **审核拒绝**：更新订单状态为 -1，记录拒绝原因和时间

## 业务流程

### 1. 用户下单流程

1. 用户选择商品加入购物车
2. 进入结算页面，选择线下支付方式
3. 提交订单，系统创建订单记录
4. 用户上传支付凭证
5. 等待管理员审核

### 2. 管理员审核流程

1. 查看待审核的线下支付订单
2. 核实支付凭证的真实性
3. 确认支付金额是否正确
4. 选择审核通过或拒绝
5. 如拒绝需填写拒绝原因

### 3. 订单状态变化

- **待支付**：订单创建成功，等待上传支付凭证
- **待审核**：用户已上传支付凭证，等待管理员审核
- **已支付**：管理员审核通过，订单进入正常流程
- **已拒绝**：管理员审核拒绝，订单状态为 -1

## 安全考虑

### 1. 数据验证
- 严格验证订单状态和支付类型
- 防止重复上传和恶意操作
- 图片格式和大小限制

### 2. 权限控制
- 只有订单所有者可以上传支付凭证
- 管理员权限验证
- 操作日志记录

### 3. 业务安全
- 防止订单状态异常变更
- 支付凭证防篡改
- 审核流程不可逆

## 优化建议

### 1. 功能扩展
- 支持批量审核功能
- 添加审核时间限制
- 支持审核意见模板

### 2. 用户体验
- 支付凭证上传进度显示
- 审核状态实时通知
- 移动端优化

### 3. 性能优化
- 图片压缩和CDN加速
- 数据库索引优化
- 缓存机制

## 注意事项

1. **订单状态管理**：确保线下支付订单的状态流转正确
2. **支付凭证存储**：建议使用云存储服务，确保图片安全可靠
3. **审核时效**：建议设置审核时间限制，避免订单长期待审核
4. **用户通知**：审核结果应及时通知用户
5. **数据备份**：重要的支付凭证数据需要定期备份

## 技术要求

- **PHP版本**：8.0+
- **Laravel版本**：11.x
- **数据库**：MySQL 5.7+
- **存储**：支持文件上传和图片处理
- **缓存**：Redis（可选）

## 版本信息

- **功能版本**：v1.0
- **创建时间**：2024-12-19
- **最后更新**：2024-12-19
- **维护状态**：活跃开发中

---

*本文档详细说明了线上订单选择线下支付方式的完整实现方案，包括API接口、核心代码、业务流程和安全考虑等各个方面。*