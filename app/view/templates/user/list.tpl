{extends file="layouts/app.tpl"}

{block name="content"}
<div class="user-list">
    <h2>用户列表</h2>
    
    <table border="1" cellpadding="10" cellspacing="0" style="width: 100%; border-collapse: collapse; margin-top: 20px;">
        <thead>
            <tr style="background: #ecf0f1;">
                <th>ID</th>
                <th>姓名</th>
                <th>邮箱</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            {foreach $users as $user}
            <tr>
                <td>{$user.id}</td>
                <td>{$user.name}</td>
                <td>{$user.email}</td>
                <td>
                    <a href="/users/{$user.id}">查看</a> |
                    <a href="/users/{$user.id}/edit">编辑</a>
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>
    
    <div style="margin-top: 20px;">
        <p>总共 {$total} 个用户</p>
    </div>
</div>
{/block}
