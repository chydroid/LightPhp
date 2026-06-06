<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($title ?? 'Welcome'); ?></title>
</head>
<body>
    <h1>Welcome to LightPHP</h1>
    <p>Site: <?php echo htmlspecialchars($site_name ?? 'Default'); ?></p>
</body>
</html>
