@if ($thread->has_attachments)
    @php
        // Extensions the browser can render inline (matches app.viewable_attachments for images/pdf).
        $previewable_ext = ['jpg', 'jpeg', 'jfif', 'pjpeg', 'pjp', 'apng', 'bmp', 'gif', 'ico', 'cur', 'png', 'tif', 'tiff', 'webp', 'pdf'];
        $previewable_count = $thread->attachments->filter(function ($attachment) use ($previewable_ext) {
            return in_array(strtolower(pathinfo($attachment->file_name, PATHINFO_EXTENSION)), $previewable_ext);
        })->count();
    @endphp
    <div class="thread-attachments">
        <i class="glyphicon glyphicon-paperclip"></i>
        <ul>
            @if ($previewable_count > 1)
                <li class="attachment-view-all-item">
                    <a href="javascript:void(0)" class="attachment-view-all"><i class="glyphicon glyphicon-picture"></i> {{ __('View all') }} ({{ $previewable_count }})</a>
                </li>
            @endif
            @foreach ($thread->attachments as $attachment)
                @php
                    $ext = strtolower(pathinfo($attachment->file_name, PATHINFO_EXTENSION));
                    $is_previewable = in_array($ext, $previewable_ext);
                    $is_image = $attachment->type == \App\Attachment::TYPE_IMAGE && $ext != 'svg';
                @endphp
                <li data-attachment-id="{{ $attachment->id }}" data-mime="{{ $attachment->mime_type }}">
                    @if ($is_previewable)
                        <a href="{{ $attachment->url() }}" class="attachment-link attachment-preview break-words" data-preview-type="{{ $is_image ? 'image' : 'iframe' }}">{{ $attachment->file_name }}</a>
                    @else
                        <a href="{{ $attachment->url() }}" class="attachment-link break-words" target="_blank">{{ $attachment->file_name }}</a>
                    @endif
                    <span class="text-help">({{ $attachment->getSizeName() }})</span>
                    <a href="{{ $attachment->url() }}" download><i class="glyphicon glyphicon-download-alt small"></i></a>
                    @action('thread.attachment_append', $attachment, $thread, $conversation, $mailbox)
                </li>
            @endforeach
            @action('thread.attachments_list_append', $thread, $conversation, $mailbox)
        </ul>
    </div>
@endif
