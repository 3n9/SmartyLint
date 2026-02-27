{* @param string $title Page title *}
{* @param array $user  Current user object *}
{block name="head"}
<head><title>{$title|escape}</title></head>
{/block}
{block name="body"}
<body>
  {block name="header"}
  <header>
    {if $user.loggedIn}
      Welcome, {$user.name|escape}!
      {if $user.isAdmin}<span class="admin">Admin</span>{/if}
    {else}
      <a href="/login">Login</a>
    {/if}
  </header>
  {/block}
  {block name="content"}Default content{/block}
  {block name="footer"}
  <footer>&copy; {$smarty.now|date_format:"%Y"}</footer>
  {/block}
</body>
{/block}
