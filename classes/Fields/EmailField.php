<?php

namespace tobimori\DreamForm\Fields;

use Kirby\Toolkit\V;

class EmailField extends Field
{
	public static function blueprint(): array
	{
		return [
			'title' => t('dreamform.email-field'),
			'preview' => 'text-field',
			'wysiwyg' => true,
			'icon' => 'email',
			'tabs' => [
				'field' => [
					'label' => t('dreamform.field'),
					'fields' => [
						'key' => 'dreamform/fields/key',
						'label' => 'dreamform/fields/label',
						'placeholder' => 'dreamform/fields/placeholder',
					]
				],
				'validation' => [
					'label' => t('dreamform.validation'),
					'fields' => [
						'required' => 'dreamform/fields/required',
						'errorMessage' => 'dreamform/fields/error-message',
					]
				]
			]
		];
	}

	public function submissionBlueprint(): array|null
	{
		return [
			'label' => $this->block()->label()->value() ?? t('dreamform.email-field'),
			'icon' => 'email',
			'type' => 'text'
		];
	}

	public function validate(): true|string
	{
		if (
			$this->block()->required()->toBool()
			&& $this->value()->isEmpty()
			|| $this->value()->isNotEmpty()
			&& !V::email($this->value()->value())
		) {
			return $this->errorMessage();
		}

		return true;
	}
}
