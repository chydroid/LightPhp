<?php
declare(strict_types=1);

namespace controller;

use core\Controller;
use model\User as UserModel;

class IndexController extends Controller
{
    public function index(): \core\Response
    {
        $data = [
            'title' => 'LightPHP Framework',
            'version' => '1.0.0',
            'features' => [
                'MVC Architecture',
                'Routing System',
                'ORM',
                'Template Engine',
                'Middleware',
                'Cache',
                'Log',
            ],
        ];

        return $this->view('index', $data);
    }

    public function about(): \core\Response
    {
        return $this->view('about', ['title' => 'About Us']);
    }

    public function contact(): \core\Response
    {
        return $this->view('contact', ['title' => 'Contact Us']);
    }
}
