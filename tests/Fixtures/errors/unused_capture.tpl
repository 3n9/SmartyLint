{capture name="my_block"}
  <div>Some captured content</div>
{/capture}

{capture assign="result"}
  <span>Assigned content</span>
{/capture}

{* 'my_block' is used via smarty.capture.my_block *}
{$smarty.capture.my_block}

{* 'result' is never used — should warn *}
<p>Content without the captured result</p>
