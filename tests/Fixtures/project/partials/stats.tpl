{* @param array $stats  Statistics data *}
{* @param array $user   Current user *}
<section class="stats">
  {foreach $stats as $stat}
    <div class="stat-card {if $stat.trend eq 'up'}positive{elseif $stat.trend eq 'down'}negative{else}neutral{/if}">
      <h3>{$stat.label|escape}</h3>
      <span class="value">{$stat.value|number_format:2}</span>
      {if $stat.delta neq 0}
        <small class="delta">{if $stat.delta gt 0}+{/if}{$stat.delta}%</small>
      {/if}
    </div>
  {foreachelse}
    <p class="empty">No statistics available.</p>
  {/foreach}
  {if $user.canExport}
    <a href="/export?format=csv" class="btn">Export CSV</a>
  {/if}
</section>
