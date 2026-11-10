<?php
namespace TerribleTools;
defined('ABSPATH') || exit;
if (defined('TERRIBLE_TOOLS_STREAM_REGISTERED')) return;
define('TERRIBLE_TOOLS_STREAM_REGISTERED', true);
add_filter('wp_stream_connectors', static function ($connectors) {
    if (!class_exists('WP_Stream\\Connector')) return $connectors;
    $connectors['terrible-tools'] = new class extends \WP_Stream\Connector {
        public $name = 'terrible-tools';
        public $actions = ['terrible_tools_event'];
        public function get_label() { return 'Terrible tools'; }
        public function get_context_labels() { return ['backups'=>'Backups','mail'=>'Mail','media'=>'Media','revision'=>'Draft updates','login'=>'Login','redirects'=>'Redirects']; }
        public function get_action_labels() { return ['completed'=>'Completed','changed'=>'Changed','replaced'=>'Replaced','restored'=>'Restored','applied'=>'Published','scheduled'=>'Scheduled','enrolled'=>'Enrolled','removed'=>'Removed']; }
        public function callback_terrible_tools_event($event, $object = 0, $unused = '') {
            // Fixed messages only: never copy credentials, mail content or provider responses into Stream.
            $messages = ['backups.completed'=>'A backup job completed.', 'backups.restored'=>'A backup was restored.', 'backups.changed'=>'Backup destination settings changed.', 'backups.removed'=>'A backup destination was removed.', 'mail.changed'=>'Mail delivery settings changed.', 'media.replaced'=>'A media file was replaced.', 'media.restored'=>'A previous media version was restored.', 'revision.applied'=>'A prepared update was published.', 'revision.scheduled'=>'A prepared update was scheduled.', 'login.enrolled'=>'An authenticator was enrolled.', 'redirects.changed'=>'Redirect rules changed.'];
            if (!isset($messages[$event])) return;
            [$context, $action] = explode('.', $event, 2);
            $this->log($messages[$event], [], (int)$object, $context, $action);
        }
    };
    return $connectors;
});
