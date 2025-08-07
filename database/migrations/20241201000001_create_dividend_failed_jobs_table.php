<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建分红失败任务表
 */
class CreateDividendFailedJobsTable extends Migrator
{
    /**
     * 执行迁移
     */
    public function change()
    {
        $table = $this->table('eb_dividend_failed_jobs', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '分红失败任务表'
        ]);
        
        $table->addColumn('id', 'integer', [
            'identity' => true,
            'signed' => false,
            'comment' => '主键ID'
        ])
        ->addColumn('type', 'string', [
            'limit' => 50,
            'default' => '',
            'comment' => '任务类型'
        ])
        ->addColumn('data', 'text', [
            'null' => true,
            'comment' => '任务数据JSON'
        ])
        ->addColumn('error_msg', 'text', [
            'null' => true,
            'comment' => '错误信息'
        ])
        ->addColumn('retry_count', 'integer', [
            'default' => 0,
            'signed' => false,
            'comment' => '重试次数'
        ])
        ->addColumn('status', 'integer', [
            'limit' => 1,
            'default' => 0,
            'comment' => '状态：0-失败，1-已处理'
        ])
        ->addColumn('created_at', 'integer', [
            'signed' => false,
            'comment' => '创建时间'
        ])
        ->addColumn('updated_at', 'integer', [
            'signed' => false,
            'null' => true,
            'comment' => '更新时间'
        ])
        ->addIndex(['type'], ['name' => 'idx_type'])
        ->addIndex(['status'], ['name' => 'idx_status'])
        ->addIndex(['created_at'], ['name' => 'idx_created_at'])
        ->create();
    }
}