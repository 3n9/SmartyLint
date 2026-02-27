{extends file="layouts/base.tpl"}

{block name="head"}
<head>
  <title>{$title|escape} - Dashboard</title>
  <link rel="stylesheet" href="/css/dashboard.css">
</head>
{/block}

{block name="content"}
<main class="dashboard">
  {include file="partials/stats.tpl" stats=$stats user=$user}
  {include file="partials/table.tpl" rows=$items caption="Recent Items" sortable=true}
  {if $user.isAdmin}
    {include file="partials/admin_panel.tpl" users=$allUsers permissions=$user.permissions}
  {/if}
</main>
{/block}
