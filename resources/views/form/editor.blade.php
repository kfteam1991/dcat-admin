<div class="{{$viewClass['form-group']}}">

    <label class="{{$viewClass['label']}} control-label">{!! $label !!}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <textarea class="form-control {{$class}}" name="{{$name}}" placeholder="{{ $placeholder }}" {!! $attributes !!} >{{ $value }}</textarea>

        @include('admin::form.help-block')

    </div>
</div>

<script require="@tinymce" init="{!! $selector !!}">
    var opts = {!! admin_javascript_json($options) !!};

    opts.selector = '#'+id;

    opts.setup = function (editor) {
        editor.on('NodeChange', function (e) {
            if (e.element.nodeName === 'IMG') {
                // 移除之前可能绑定的 keydown 事件，避免重复绑定
                editor.off('keydown').on('keydown', function (event) {
                    if (event.key === 'Delete' || event.key === 'Backspace') {
                        event.stopPropagation(); // 阻止默认行为
                        // event.preventDefault();
                        let selectedNode = editor.selection.getNode();
                        console.log(selectedNode.src);
                        // 发送 AJAX 请求删除服务器上的图片
                        $.ajax({
                            url: '/admin/dcat-api/tinymce/delete',  // 删除图片接口
                            type: 'POST',
                            data: {
                                image: selectedNode.src,
                                _token: $('meta[name="csrf-token"]').attr('content')  // CSRF 令牌
                            },
                            success: function (response) {
                                // 删除成功后,从编辑器中移除图片
                                editor.dom.remove(selectedNode);
                            },
                            error: function (xhr) {
                                console.error('Error deleting image:', xhr);
                            }
                        });
                    }
                });
            }
        });
    }
    if (! opts.init_instance_callback) {
        opts.init_instance_callback = function (editor) {
            editor.on('Change', function(e) {
                $this.val(String(e.target.getContent()).replace('<p><br data-mce-bogus="1"></p>', '').replace('<p><br></p>', ''));
            });
        }
    }

    tinymce.init(opts)
</script>
