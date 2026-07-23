<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Covers ARMS-45: images/pdf attachments open in a modal (gallery when there
 * are 2+ previewable ones); everything else keeps opening in a new tab.
 */
class AttachmentPreviewModalTest extends TestCase
{
    use DatabaseTransactions;

    protected $thread;

    protected function setUp(): void
    {
        parent::setUp();

        // ConversationFactory falls back to a random existing User for
        // created_by_user_id when it isn't passed, which is order-dependent
        // on whatever else happens to be in the DB. Create our own instead.
        $user = factory(User::class)->create();
        $mailbox = factory(Mailbox::class)->create();
        $customer = factory(Customer::class)->create();

        $conversation = factory(Conversation::class)->create([
            'mailbox_id' => $mailbox->id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $user->id,
        ]);

        $this->thread = factory(Thread::class)->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            // ThreadFactory only defines a local $customer var (used to build
            // a default "to") when customer_id isn't passed; passing "to"
            // explicitly here avoids tripping its undefined-variable branch.
            'to' => json_encode([$customer->getMainEmail() ?: 'customer@example.org']),
            'has_attachments' => true,
        ]);
    }

    protected function makeAttachment(array $attrs)
    {
        // Attachment has no $fillable/$guarded set, so ->fill() throws
        // MassAssignmentException; set attributes directly instead.
        $attachment = new Attachment();
        $attachment->thread_id = $this->thread->id;
        $attachment->file_dir = '1/2/3/';
        $attachment->size = 100;
        $attachment->token_type = Attachment::TOKEN_TYPE_SHA256;
        $attachment->file_name = $attrs['file_name'];
        $attachment->mime_type = $attrs['mime_type'];
        $attachment->type = $attrs['type'];
        $attachment->save();

        return $attachment;
    }

    protected function renderAttachmentsPartial()
    {
        return view('conversations.partials.thread_attachments', [
            'thread' => $this->thread->fresh(),
            'conversation' => $this->thread->conversation,
            'mailbox' => $this->thread->conversation->mailbox,
        ])->render();
    }

    public function testImageAndPdfOpenInModalWhileZipStaysAsBefore()
    {
        $this->makeAttachment(['file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'type' => Attachment::TYPE_IMAGE]);
        $this->makeAttachment(['file_name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'type' => Attachment::TYPE_APPLICATION]);
        $this->makeAttachment(['file_name' => 'archive.zip', 'mime_type' => 'application/zip', 'type' => Attachment::TYPE_APPLICATION]);

        $html = $this->renderAttachmentsPartial();

        $this->assertStringContainsString('View all (2)', $html);
        $this->assertStringContainsString('data-preview-type="image"', $html);
        $this->assertStringContainsString('data-preview-type="iframe"', $html);

        // The zip must keep its old behaviour untouched: target="_blank", no
        // attachment-preview class, no data-preview-type attribute.
        $this->assertMatchesRegularExpression(
            '/<a href="[^"]*archive\.zip[^"]*" class="attachment-link break-words" target="_blank">archive\.zip<\/a>/',
            $html
        );
    }

    public function testViewAllButtonHiddenWithOnlyOnePreviewableAttachment()
    {
        $this->makeAttachment(['file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'type' => Attachment::TYPE_IMAGE]);
        $this->makeAttachment(['file_name' => 'archive.zip', 'mime_type' => 'application/zip', 'type' => Attachment::TYPE_APPLICATION]);

        $html = $this->renderAttachmentsPartial();

        $this->assertStringContainsString('data-preview-type="image"', $html);
        $this->assertStringNotContainsString('attachment-view-all', $html);
    }

    public function testRemovingExtensionFromViewableAttachmentsConfigDisablesItsPreview()
    {
        // If an admin removes jpg from app.viewable_attachments (the server
        // will force-download it), the modal must stop offering a preview
        // for it too instead of opening on a file it can never render.
        config(['app.viewable_attachments' => ['pdf']]);

        $this->makeAttachment(['file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'type' => Attachment::TYPE_IMAGE]);
        $this->makeAttachment(['file_name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'type' => Attachment::TYPE_APPLICATION]);

        $html = $this->renderAttachmentsPartial();

        $this->assertStringNotContainsString('data-preview-type="image"', $html);
        $this->assertStringContainsString('data-preview-type="iframe"', $html);
        // Only one previewable attachment left (the pdf), so no gallery button.
        $this->assertStringNotContainsString('attachment-view-all', $html);
    }
}
