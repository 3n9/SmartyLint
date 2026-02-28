<p>{$smarty.get.q}</p>
<p>{$smarty.post.name}</p>
{if $smarty.session.user}<p>Welcome</p>{/if}
{include file="partial.tpl" title=$smarty.request.title}
<p>{$safe_var}</p>
