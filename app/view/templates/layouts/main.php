<!DOCTYPE html>
<html>
<head>
    <title>Main Layout</title>
</head>
<body>
    <header><h1>Site: <?php echo htmlspecialchars($site_name ?? 'Default'); ?></h1></header>
    <main><?php echo $__content__; ?></main>
</body>
</html>
