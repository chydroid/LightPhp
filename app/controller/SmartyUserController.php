<?php
declare(strict_types=1);

namespace controller;

use core\Controller;

class SmartyUserController extends Controller
{
    public function index()
    {
        $users = [
            ['id' => 1, 'name' => '张三', 'email' => 'zhangsan@example.com'],
            ['id' => 2, 'name' => '李四', 'email' => 'lisi@example.com'],
            ['id' => 3, 'name' => '王五', 'email' => 'wangwu@example.com'],
        ];

        $view = new \view\SmartyView(
            SMARTY_TEMPLATE_PATH,
            STORAGE_PATH . 'cache/smarty/compile/',
            STORAGE_PATH . 'cache/smarty/cache/'
        );

        return $view->display('user/list.tpl', [
            'title' => '用户列表 - Smarty',
            'users' => $users,
            'total' => count($users),
            'site_name' => 'LightPHP Smarty Demo',
        ]);
    }
}
