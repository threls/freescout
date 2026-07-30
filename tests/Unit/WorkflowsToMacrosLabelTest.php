<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * "Workflows" should read as "Macros" everywhere it is shown, to match the
 * Zendesk term ARMS's agents already use (ARMS-49, task 1). Done via a
 * translation override (resources/lang/en.json), the same mechanism the
 * Closed -> Solved, Active -> New and Note -> Internal Note renames use.
 *
 * The Workflows module is paid and runtime-installed, so it isn't in this
 * repo — but its strings go through __() and therefore resolve against our
 * en.json, exactly as ActiveToNewLabel established when its own
 * __('Active') checkbox got swept up by that override. No file in that
 * module is touched here.
 *
 * Unlike "Active", every translatable string in the install containing
 * "workflow" refers to this one feature (confirmed by grepping the live
 * server for every __() string containing the word), so there are no
 * collisions to work around and no patch command is needed.
 */
class WorkflowsToMacrosLabelTest extends TestCase
{
    /**
     * Every translatable string in the install containing "workflow", as
     * found on the live server. If the Workflows module adds more in a
     * future version they belong here too.
     */
    const SOURCE_STRINGS = [
        'Workflows',
        'Workflow',
        'New Workflow',
        'Run Workflow',
        'Workflow Name',
        'Workflows Help',
        'Workflow created',
        'Workflow updated',
        'Workflow deleted',
        'Workflow not found',
        'Workflow :name deactivated',
        'Workflow :workflow has run',
        'Workflow :workflow was triggered',
        'Workflow :workflow was triggered for conversation #:conversation_number',
        'Triggered by the :workflow workflow',
        ':person ran the :workflow workflow',
        ':person ran the :workflow workflow for conversation #:conversation_number',
        'Stop Processing Workflows',
        'User running the Workflow',
        'Users are allowed to manage workflows',
        'Delete this workflow?',
        'Apply this workflow to all previous conversations matching conditions',
        'When this options is enabled the workflow may be applied to a large number of the past conversations and changes can not be undone. It is recommended to backup the application before turning this option on.',
        'Deleting this mailbox will remove all historical data and deactivate related workflows and reports.',
    ];

    public function test_the_headline_labels_read_macros()
    {
        $this->assertSame('Macros', __('Workflows'));
        $this->assertSame('Macro', __('Workflow'));
        $this->assertSame('New Macro', __('New Workflow'));
        $this->assertSame('Run Macro', __('Run Workflow'));
    }

    /**
     * The point of the ticket: no agent-facing string should still say
     * "workflow". Covers the long sentences too, where replacing only the
     * first occurrence would otherwise slip through unnoticed.
     */
    public function test_no_relabelled_string_still_says_workflow()
    {
        foreach (self::SOURCE_STRINGS as $source) {
            $translated = __($source);

            $this->assertNotSame(
                $source,
                $translated,
                'Missing en.json override for: '.$source
            );

            $this->assertDoesNotMatchRegularExpression(
                '/workflow/i',
                $this->visibleWording($translated),
                'Still says "workflow" after relabelling: '.$source
            );
        }
    }

    /**
     * A rename that drops a placeholder would render as literal text (e.g.
     * a thread log line losing the macro's own name), so each one has to
     * survive into the translated string.
     */
    public function test_placeholders_survive_the_rename()
    {
        $expected = [
            'Workflow :name deactivated' => [':name'],
            'Workflow :workflow has run' => [':workflow'],
            'Workflow :workflow was triggered' => [':workflow'],
            'Workflow :workflow was triggered for conversation #:conversation_number' => [':workflow', ':conversation_number'],
            'Triggered by the :workflow workflow' => [':workflow'],
            ':person ran the :workflow workflow' => [':person', ':workflow'],
            ':person ran the :workflow workflow for conversation #:conversation_number' => [':person', ':workflow', ':conversation_number'],
        ];

        foreach ($expected as $source => $placeholders) {
            $translated = __($source);

            foreach ($placeholders as $placeholder) {
                $this->assertStringContainsString(
                    $placeholder,
                    $translated,
                    $placeholder.' was dropped from: '.$source
                );
            }
        }
    }

    /**
     * The mailbox-deletion warning is the one relabelled string that also
     * names an unrelated feature ("...deactivate related workflows and
     * reports"). Reports must survive untouched, both inside that sentence
     * and as a label of its own.
     */
    public function test_reports_is_not_swept_up_by_the_rename()
    {
        $this->assertStringContainsString(
            'reports',
            __('Deleting this mailbox will remove all historical data and deactivate related workflows and reports.')
        );

        $this->assertSame('Reports', __('Reports'));
    }

    /**
     * Guards against a half-finished edit to the override file itself: any
     * entry keyed on a workflow string must have fully replaced the word in
     * its value, not just the first occurrence.
     */
    public function test_override_file_has_no_leftover_workflow_wording()
    {
        $overrides = json_decode(file_get_contents(resource_path('lang/en.json')), true);

        $this->assertNotEmpty($overrides, 'en.json could not be read');

        foreach ($overrides as $key => $value) {
            if (!preg_match('/workflow/i', $key)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/workflow/i',
                $this->visibleWording($value),
                'en.json value for "'.$key.'" still says "workflow"'
            );
        }
    }

    /**
     * Strips :placeholder names so only wording an agent actually reads is
     * checked for the word "workflow". The Workflows module names one of
     * its own placeholders `:workflow` (as in "Triggered by the :workflow
     * workflow") — that name is the module's, not ours, and renaming it
     * would break the substitution and render the placeholder literally.
     */
    protected function visibleWording($string)
    {
        return preg_replace('/:[a-z_%]+/i', '', $string);
    }
}
