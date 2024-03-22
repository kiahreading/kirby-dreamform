<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use Kirby\Data\Yaml;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;
use tobimori\DreamForm\DreamForm;

if (
	version_compare(App::version() ?? '0.0.0', '4.0.0', '<') === true ||
	version_compare(App::version() ?? '0.0.0', '5.0.0', '>') === true
) {
	throw new Exception('Kirby DreamForm requires Kirby 4');
}

// register all classes (guards, fields, actions)
DreamForm::register(
	\tobimori\DreamForm\Actions\AbortAction::class,
	\tobimori\DreamForm\Actions\ConditionalAction::class,
	\tobimori\DreamForm\Actions\DiscordWebhookAction::class,
	\tobimori\DreamForm\Actions\EmailAction::class,
	\tobimori\DreamForm\Actions\RedirectAction::class,
	\tobimori\DreamForm\Actions\WebhookAction::class,
	\tobimori\DreamForm\Fields\ButtonField::class,
	\tobimori\DreamForm\Fields\CheckboxField::class,
	\tobimori\DreamForm\Fields\EmailField::class,
	\tobimori\DreamForm\Fields\HiddenField::class,
	\tobimori\DreamForm\Fields\SelectField::class,
	\tobimori\DreamForm\Fields\TextareaField::class,
	\tobimori\DreamForm\Fields\TextField::class,
	\tobimori\DreamForm\Guards\CsrfGuard::class,
	\tobimori\DreamForm\Guards\HoneypotGuard::class
);

// register plugin
App::plugin('tobimori/dreamform', [
	'options' => require __DIR__ . '/config/options.php',
	'pageModels' => [
		'forms' => 'tobimori\DreamForm\Models\FormsPage',
		'form' => 'tobimori\DreamForm\Models\FormPage',
		'submission' => 'tobimori\DreamForm\Models\SubmissionPage',
	],
	'hooks' => require_once __DIR__ . '/config/hooks.php',
	'blueprints' => [
		'pages/forms' => __DIR__ . '/blueprints/pages/forms.yml',
		'pages/form' => __DIR__ . '/blueprints/pages/form.yml',
		'pages/submission' => require_once __DIR__ . '/blueprints/pages/submission.php',

		'dreamform/sections/submissions-table' => require_once __DIR__ . '/blueprints/sections/submissions-table.php',
		'dreamform/fields/key' => __DIR__ . '/blueprints/fields/key.yml',
		'dreamform/fields/label' => __DIR__ . '/blueprints/fields/label.yml',
		'dreamform/fields/placeholder' => __DIR__ . '/blueprints/fields/placeholder.yml',
		'dreamform/fields/error-message' => __DIR__ . '/blueprints/fields/error-message.yml',
		'dreamform/fields/required' => __DIR__ . '/blueprints/fields/required.yml',
		'dreamform/fields/static-dynamic-toggles' => __DIR__ . '/blueprints/fields/static-dynamic-toggles.yml',
		'dreamform/fields/actions' => require_once __DIR__ . '/blueprints/fields/actions.php',
		'dreamform/fields/fields' => require_once __DIR__ . '/blueprints/fields/fields.php',
		'dreamform/fields/field' => require_once __DIR__ . '/blueprints/fields/field.php',
		'dreamform/fields/form' => require_once __DIR__ . '/blueprints/fields/form.php',
		'dreamform/fields/email-template' => require_once __DIR__ . '/blueprints/fields/email-template.php',
		'dreamform/fields/writer-with-fields' => require_once __DIR__ . '/blueprints/fields/writer-with-fields.php',
	],
	'snippets' => [
		'dreamform/form' => __DIR__ . '/snippets/form.php',
		'dreamform/guards' => __DIR__ . '/snippets/guards.php',
		'dreamform/success' => __DIR__ . '/snippets/success.php',
		'dreamform/inactive' => __DIR__ . '/snippets/inactive.php',
		'dreamform/fields/text' => __DIR__ . '/snippets/fields/text.php',
		'dreamform/fields/textarea' => __DIR__ . '/snippets/fields/textarea.php',
		'dreamform/fields/email' => __DIR__ . '/snippets/fields/email.php',
		'dreamform/fields/hidden' => __DIR__ . '/snippets/fields/hidden.php',
		'dreamform/fields/select' => __DIR__ . '/snippets/fields/select.php',
		'dreamform/fields/checkbox' => __DIR__ . '/snippets/fields/checkbox.php',
		'dreamform/fields/button' => __DIR__ . '/snippets/fields/button.php',
		'dreamform/guards/csrf' => __DIR__ . '/snippets/guards/csrf.php',
		'dreamform/guards/honeypot' => __DIR__ . '/snippets/guards/honeypot.php',
	],
	// get all files from /translations and register them as language files
	'translations' => A::keyBy(
		A::map(
			Dir::read(__DIR__ . '/translations'),
			function ($file) {
				$translations = [];
				foreach (Yaml::decode(F::read(__DIR__ . '/translations/' . $file)) as $key => $value) {
					$translations["dreamform.{$key}"] = $value;
				}

				return A::merge(
					['lang' => F::name($file)],
					$translations
				);
			}
		),
		'lang'
	)
]);
