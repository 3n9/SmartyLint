{function name="render_item"}
  <li class="{$class|default:'item'}">{$label|escape}</li>
{/function}

{function name="render_section"}
  <section id="{$id}">
    <h2>{$heading|escape}</h2>
    {foreach $items as $item}
      {render_item label=$item.name class=$item.type}
    {/foreach}
  </section>
{/function}

{function name="dead_function"}
  <span>This function is never called</span>
{/function}

{render_section id="main" heading="Items" items=$menuItems}
