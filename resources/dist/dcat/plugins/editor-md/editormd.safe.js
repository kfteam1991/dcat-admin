/*!
 * dcat-admin security patch for Editor.md 1.5.0 (unmaintained upstream).
 *
 * 1. Replaces editormd.filterHTMLTags: the original implementation parses untrusted HTML
 *    with jQuery ($(html)), which executes inline handlers such as <img onerror> while "filtering".
 *    The replacement sanitises inside an inert document (DOMParser), where nothing executes or loads.
 * 2. Neutralises javascript: / data: / vbscript: URLs in rendered markdown links and images.
 */
(function (window) {
    var editormd = window.editormd;

    if (!editormd || editormd.__dcatSafe) {
        return;
    }

    editormd.__dcatSafe = true;

    var URL_ATTRS = ['href', 'src', 'xlink:href', 'action', 'formaction', 'data', 'poster', 'background', 'cite', 'ping'];
    // Always removed, whatever the htmlDecode option says.
    var BASE_TAGS = ['script'];

    function isDangerousUrl(url) {
        var value;

        try {
            value = decodeURIComponent(unescape(String(url == null ? '' : url)));
        } catch (e) {
            return true;
        }

        value = value.replace(/&#x?[0-9a-f]+;?|&[a-z]+;|[^\w:]/gi, '').toLowerCase();

        if (/^dataimage(png|jpe?g|gif|webp|bmp)/.test(value.replace(':', ''))) {
            return false;
        }

        return /^(javascript|vbscript|data):/.test(value);
    }

    function validName(name) {
        return /^[a-z][a-z0-9-]*$/.test(name);
    }

    editormd.filterHTMLTags = function (html, filters) {
        html = String(html == null ? '' : html);

        if (typeof window.DOMParser === 'undefined') {
            // No inert parser available: fail closed by escaping everything.
            return html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        var expression = typeof filters === 'string' ? filters.split('|') : [''];
        var tags = BASE_TAGS.concat(expression[0].toLowerCase().split(',')).map(function (tag) {
            return tag.replace(/^\s+|\s+$/g, '');
        }).filter(validName);
        var attrRule = typeof expression[1] === 'string' ? expression[1].toLowerCase() : '';
        var removeAllAttrs = attrRule === '*';
        var removeAttrs = attrRule.split(',').map(function (attr) {
            return attr.replace(/^\s+|\s+$/g, '');
        });

        var doc = new window.DOMParser().parseFromString('<!DOCTYPE html><html><body>' + html + '</body></html>', 'text/html');
        var body = doc.body;
        var i, j;

        var blocked = body.querySelectorAll(tags.join(','));

        for (i = 0; i < blocked.length; i++) {
            if (blocked[i].parentNode) {
                blocked[i].parentNode.removeChild(blocked[i]);
            }
        }

        var elements = body.querySelectorAll('*');

        for (i = 0; i < elements.length; i++) {
            var el = elements[i];
            var attrs = Array.prototype.slice.call(el.attributes);

            for (j = 0; j < attrs.length; j++) {
                var name = attrs[j].name.toLowerCase();

                if (
                    removeAllAttrs
                    || name.indexOf('on') === 0
                    || name === 'srcdoc'
                    || removeAttrs.indexOf(name) !== -1
                    || (URL_ATTRS.indexOf(name) !== -1 && isDangerousUrl(attrs[j].value))
                    || (name === 'style' && /expression\s*\(|javascript:|url\s*\(\s*['"]?\s*javascript:/i.test(attrs[j].value))
                ) {
                    el.removeAttribute(attrs[j].name);
                }
            }
        }

        return body.innerHTML;
    };

    if (!editormd.markedRenderer) {
        return;
    }

    var original = editormd.markedRenderer;

    editormd.markedRenderer = function () {
        var renderer = original.apply(this, arguments);

        ['link', 'image'].forEach(function (name) {
            var fn = renderer[name];

            if (typeof fn !== 'function') {
                return;
            }

            renderer[name] = function (href) {
                var args = Array.prototype.slice.call(arguments);

                if (isDangerousUrl(href)) {
                    args[0] = '#';
                }

                return fn.apply(this, args);
            };
        });

        return renderer;
    };
})(window);
