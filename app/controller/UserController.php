<?php
declare(strict_types=1);

namespace controller;

use core\Controller;
use core\Request;

class UserController extends Controller
{
    public function index(): \core\Response
    {
        $users = [
            ['id' => 1, 'name' => '张三', 'email' => 'zhangsan@example.com'],
            ['id' => 2, 'name' => '李四', 'email' => 'lisi@example.com'],
            ['id' => 3, 'name' => '王五', 'email' => 'wangwu@example.com'],
        ];

        return $this->json([
            'users' => $users,
            'total' => count($users),
        ]);
    }

    public function show(int $id): \core\Response
    {
        $user = [
            'id' => $id,
            'name' => '张三',
            'email' => 'zhangsan@example.com',
        ];

        return $this->json(['user' => $user]);
    }

    public function store(Request $request): \core\Response
    {
        $data = $request->only(['name', 'email']);
        
        if (empty($data['name']) || empty($data['email'])) {
            return $this->error('Name and email are required', 422);
        }

        $user = [
            'id' => rand(100, 999),
            'name' => $data['name'],
            'email' => $data['email'],
        ];

        return $this->success($user, 'User created successfully');
    }

    public function update(int $id, Request $request): \core\Response
    {
        $data = $request->only(['name', 'email']);
        
        $user = [
            'id' => $id,
            'name' => $data['name'] ?? 'Updated Name',
            'email' => $data['email'] ?? 'updated@example.com',
        ];

        return $this->success($user, 'User updated successfully');
    }

    public function destroy(int $id): \core\Response
    {
        return $this->success(['id' => $id], 'User deleted successfully');
    }
}
