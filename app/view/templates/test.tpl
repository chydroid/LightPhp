<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
</head>
<body>
    <h1>{$title}</h1>
    <p>Welcome, {$name}!</p>
    <ul>
    {foreach $items as $item}
        <li>{$item}</li>
    {/foreach}
    </ul>
</body>
</html>