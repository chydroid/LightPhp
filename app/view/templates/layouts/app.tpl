<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title|default:'LightPHP'}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
        header { background: #2c3e50; color: white; padding: 20px 0; margin-bottom: 30px; }
        header h1 { text-align: center; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        footer { text-align: center; padding: 20px; color: #7f8c8d; margin-top: 40px; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>{$site_name|default:'LightPHP Framework'}</h1>
        </div>
    </header>
    
    <main class="container">
        {$_content_}
    </main>
    
    <footer>
        <p>&copy; 2024 LightPHP Framework. All rights reserved.</p>
    </footer>
</body>
</html>
