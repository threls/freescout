@if ($thread->has_attachments)
    @php
        // Image/pdf extensions we know how to render inline (img tag / iframe).
        // Intersected with app.viewable_attachments so that if an admin removes
        // an extension from that config (forcing a download server-side), we
        // stop offering a preview for it too instead of opening a modal that
        // can only ever fall back.
        $previewable_ext = array_intersect(
            ['jpg', 'jpeg', 'jfif', 'pjpeg', 'pjp', 'apng', 'bmp', 'gif', 'ico', 'cur', 'png', 'tif', 'tiff', 'webp', 'pdf'],
            config('app.viewable_attachments', [])
        );
        $attachments_meta = $thread->attachments->map(function ($attachment) use ($previewable_ext) {
            $ext = strtolower(pathinfo($attachment->file_name, PATHINFO_EXTENSION));

            return [
                'attachment' => $attachment,
                // svg is deliberately excluded from $previewable_ext above (it's
                // forced to download server-side to avoid an XSS vector), so no
                // separate svg check is needed here.
                'is_image' => in_array($ext, $previewable_ext) && $attachment->type == \App\Attachment::TYPE_IMAGE,
                'is_previewable' => in_array($ext, $previewable_ext),
            ];
        });
        $previewable_count = $attachments_meta->where('is_previewable', true)->count();
    @endphp
    <div class="thread-attachments">
        <i class="glyphicon glyphicon-paperclip"></i>
        <ul>
            @if ($previewable_count > 1)
                <li class="attachment-view-all-item">
                    <a href="javascript:void(0)" class="attachment-view-all"><i class="glyphicon glyphicon-picture"></i> {{ __('View all') }} ({{ $previewable_count }})</a>
                </li>
            @endif
            @foreach ($attachments_meta as $meta)
                @php $attachment = $meta['attachment']; @endphp
                <li data-attachment-id="{{ $attachment->id }}" data-mime="{{ $attachment->mime_type }}">
                    @if ($meta['is_previewable'])
                        <a href="{{ $attachment->url() }}" class="attachment-link attachment-preview break-words" data-preview-type="{{ $meta['is_image'] ? 'image' : 'iframe' }}">{{ $attachment->file_name }}</a>
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
