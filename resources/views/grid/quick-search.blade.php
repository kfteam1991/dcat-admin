<style>::-ms-clear,::-ms-reveal{display: none;}</style>

<form pjax-container action="{!! $action !!}" class="input-no-border quick-search-form d-md-inline-block" style="display:none;margin-right: 16px">
    <div class="table-filter">
        <label style="width: {{ $width }}rem">
            <input
                    type="search"
                    class="form-control form-control-sm quick-search-input"
                    placeholder="{{ $placeholder }}"
                    name="{{ $key }}"
                    value="{{ $value }}"
                    auto="{{ $auto ? '1' : '0' }}"
            >
            <span class="clear-input quick-search-clear" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer;">
                <i class="fa fa-times"></i>
            </span>
        </label>
    </div>
</form>
