{* @param array  $rows     Table rows *}
{* @param string $caption  Table caption *}
{* @param bool   $sortable Enable sortable headers *}
<table class="data-table">
  <caption>{$caption|escape}</caption>
  <thead>
    <tr>
      {foreach $rows[0]|array_keys as $col}
        <th {if $sortable}data-sort="{$col}"{/if}>{$col|capitalize}</th>
      {/foreach}
    </tr>
  </thead>
  <tbody>
    {foreach $rows as $row}
      <tr class="{cycle values='odd,even'}">
        {foreach $row as $cell}
          <td>{$cell|escape}</td>
        {/foreach}
      </tr>
    {foreachelse}
      <tr><td colspan="99">No data.</td></tr>
    {/foreach}
  </tbody>
</table>
