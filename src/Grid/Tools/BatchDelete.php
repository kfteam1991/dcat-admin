<?php

namespace Dcat\Admin\Grid\Tools;

use Dcat\Admin\Grid\BatchAction;

class BatchDelete extends BatchAction
{
    public function __construct($title)
    {
        $this->title = $title;
    }

    public function render()
    {
        $redirect = request()->fullUrl();

        return <<<HTML
    <button  data-name="{$this->parent->getName()}" 
        class="btn btn-danger grid-refresh btn-mini btn-sm"
        data-action="batch-delete" 
        data-redirect="{$redirect}"
        data-url="{$this->resource()}"><i class="feather icon-trash"></i> {$this->title}</button>
HTML;
    }
}
