{* @param array $users       All users list *}
{* @param array $permissions Current user permissions *}

{function name="user_row"}
<tr>
  <td>{$user.name|escape}</td>
  <td>{$user.email|escape}</td>
  <td>
    {if $user.active}<span class="badge green">Active</span>
    {else}<span class="badge red">Inactive</span>
    {/if}
  </td>
  <td>
    {if $can_edit}<a href="/admin/users/{$user.id}/edit">Edit</a>{/if}
    {if $can_delete}<a href="/admin/users/{$user.id}/delete" class="danger">Delete</a>{/if}
  </td>
</tr>
{/function}

{function name="permission_badge"}
<span class="permission {if $granted}granted{else}denied{/if}">{$permission_name|escape}</span>
{/function}

<section class="admin-panel">
  <h2>User Management</h2>
  <table>
    <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      {foreach $users as $user}
        {user_row user=$user can_edit=$permissions.edit can_delete=$permissions.delete}
      {/foreach}
    </tbody>
  </table>

  <h2>Permissions</h2>
  <div class="permissions">
    {foreach ['edit','delete','export','import'] as $perm}
      {permission_badge permission_name=$perm granted=($permissions[$perm] ?? false)}
    {/foreach}
  </div>
</section>
