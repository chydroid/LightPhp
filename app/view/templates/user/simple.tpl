{* This is a comment *}
{$title|default:'No Title'}
{if isset($users)}
<table>
{foreach $users as $user}
    <tr>
        <td>{$user.id}</td>
        <td>{$user.name}</td>
        <td>{$user.email}</td>
    </tr>
{/foreach}
</table>
{else}
<p>No users found</p>
{/if}