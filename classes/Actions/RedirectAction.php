<?php

namespace tobimori\DreamForm\Actions;

/**
 * Action for redirecting the user to a success page after submitting.
 */
class RedirectAction extends Action
{
	public static function blueprint(): array
	{
		return [
			'title' => t('dreamform.redirect-action'),
			'preview' => 'fields',
			'wysiwyg' => true,
			'icon' => 'shuffle',
			'tabs' => [
				'settings' => [
					'label' => t('dreamform.settings'),
					'fields' => [
						'redirectTo' => [
							'label' => 'dreamform.redirect-to',
							'type' => 'link',
							'options' => [
								'url',
								'page',
								'file'
							],
							'required' => true
						]
					]
				]
			]
		];
	}

	public function run(): void
	{
		$redirect = $this->block()->redirectTo()->toUrl();
		if ($redirect) {
			$this->submission()->setRedirect($redirect);
		}
	}
}
