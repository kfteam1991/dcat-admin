<div {!! $attributes !!}><textarea style="display:none;">{{ $content }}</textarea></div>

<script first>
    var ele = window.Element;
    Dcat.eMatches = ele.prototype.matches ||
        ele.prototype.msMatchesSelector ||
        ele.prototype.webkitMatchesSelector;
</script>

<script require="@editor-md">
    editormd.markdownToHTML('{{ $id }}', {!! admin_javascript_json($options) !!});

    // 移除 javascript: / data: / vbscript: 等危险链接，防止存储型 XSS
    $('#{{ $id }}').find('a[href],area[href]').each(function () {
        if (/^[\s\u0000-\u001f]*(javascript|data|vbscript):/i.test(this.getAttribute('href') || '')) {
            this.removeAttribute('href');
        }
    });

    Element.prototype.matches = Dcat.eMatches;
</script>