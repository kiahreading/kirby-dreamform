<?php

namespace tobimori\DreamForm\Models;

use DateTime;
use IntlDateFormatter;
use Kirby\Cms\App;
use Kirby\Cms\Blocks;
use Kirby\Cms\Collection;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Responder;
use Kirby\Content\Content;
use Kirby\Content\Field;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\V;
use tobimori\DreamForm\DreamForm;
use tobimori\DreamForm\Fields\Field as FormField;
use tobimori\DreamForm\Permissions\SubmissionPermissions;
use tobimori\DreamForm\Support\Htmx;

class SubmissionPage extends BasePage
{
	/**
	 * Returns the submission referer (for PRG redirects)
	 */
	public function referer(): string|null
	{
		return $this->content()->get('dreamform_referer')->value();
	}

	/**
	 * Looks up the referer as page in the site structure
	 */
	public function findRefererPage(): Page|null
	{
		return DreamForm::findPageOrDraftRecursive($this->referer());
	}

	/**
	 * Returns the value of a field in the submission content by its ID
	 */
	public function valueForId(string $id): Field|null
	{
		/** @var tobimori\DreamForm\Fields\Field|null $field */
		$field = $this->form()->fields()->find($id);
		if ($field) {
			if (!($key = $field->key())) {
				return null;
			}

			return $this->content()->get($key);
		}

		return null;
	}

	/**
	 * Returns the value of a field in the submission content by its key
	 */
	public function valueFor(string $key): Field|null
	{
		$key = DreamForm::normalizeKey($key);
		$field = $this->content()->get($key);
		if ($field->isEmpty()) {
			// check if the field is prefillable from url params
			$field = $this->parent()->valueFromQuery($key);
		}

		return $field;
	}

	/**
	 * Returns the values of all fields in the submission content as content object
	 */
	public function values(): Content
	{
		$values = [];
		foreach ($this->form()->fields() as $field) {
			if ($field::hasValue()) {
				$values[$field->key()] = $this->valueFor($field->key());
			}
		}

		return new Content($values, $this);
	}

	/**
	 * Returns the error message for a field in the submission state
	 */
	public function errorFor(string $key = null): string|null
	{
		if ($key === null) {
			return $this->state()->get('error')->value();
		}

		$key = Str::replace($key, '-', '_');
		$errors = $this->state()->get('errors')->toObject();
		return $errors->get($key)->value();
	}

	/**
	 * Sets an error in the submission state
	 */
	public function setError(string $message, string $field = null): static
	{
		$state = $this->state()->toArray();
		$state['success'] = false;
		if ($field) {
			$state['errors'][$field] = $message;
		} else {
			$state['error'] = $message;
		}

		// manually update content to avoid saving it to disk before the form submission is finished
		$this->content = $this->content()->update([
			'dreamform_state' => $state
		]);

		return $this;
	}

	/**
	 * Removes an error from the submission state
	 */
	public function removeError(string $field = null): static
	{
		$state = $this->state()->toArray();
		if ($field) {
			unset($state['errors'][$field]);
		} else {
			$state['error'] = null;
		}

		if (empty($state['errors']) && !$state['error']) {
			$state['success'] = true;
		}

		$this->content = $this->content()->update([
			'dreamform_state' => $state
		]);

		return $this;
	}

	/**
	 * Returns the raw field value from the request body
	 */
	public static function valueFromBody(string $key): mixed
	{
		$key = DreamForm::normalizeKey($key);
		$body = App::instance()->request()->body()->toArray();

		$body = array_combine(
			A::map(array_keys($body), function ($key) {
				return DreamForm::normalizeKey($key);
			}),
			array_values($body)
		);

		return $body[$key] ?? null;
	}

	/**
	 * Set a field with the value from the request
	 */
	public function updateFieldFromRequest(FormField $field): FormField
	{
		return $field->setValue(
			new Field(
				$this,
				$key = $field->key(),
				$this->valueFromBody($key)
			)
		);
	}

	/**
	 * Sets a field in the submission content
	 */
	public function setField(FormField $field): static
	{
		$this->content = $this->content()->update([
			$field->key() => $field->value()->value()
		]);

		return $this;
	}

	/**
	 * Create actions from the form's content
	 */
	public function createActions(Blocks $blocks = null): Collection
	{
		$blocks ??= $this->form()->content()->get('actions')->toBlocks();

		$actions = [];
		foreach ($blocks as $block) {
			$type = Str::replace($block->type(), '-action', '');

			$action = DreamForm::action($type, $block, $this);
			if ($action) {
				$actions[] = $action;
			}
		}

		return new Collection($actions, []);
	}

	/**
	 * Sets the redirect URL in the submission state
	 */
	public function setRedirect(string $url): static
	{
		$state = $this->state()->toArray();
		$state['redirect'] = $url;

		$this->content = $this->content()->update(['dreamform_state' => $state]);

		return $this;
	}

	/**
	 * Returns the action state
	 */
	public function actionState(): Content
	{
		return $this->state()->actions()->toObject();
	}

	/**
	 * Sets the action state of the submission
	 */
	public function setActionState(array $data): static
	{
		$state = $this->state()->toArray();
		$state['actions'] = A::merge($state['actions'], $data);

		$this->content = $this->content()->update(['dreamform_state' => $state]);

		return $this;
	}

	/**
	 * Returns a Response that redirects the user to the URL set in the submission state
	 */
	public function redirect(): Responder
	{
		if (!$this->state()->get('redirect')->value()) {
			return $this->redirectToReferer();
		}

		return App::instance()->response()->redirect(
			$this->state()->get('redirect')->value()
		);
	}

	/**
	 * Returns a Response that redirects the user to the referer URL
	 */
	public function redirectToReferer(): Responder
	{
		$kirby = App::instance();
		if ($kirby->option('tobimori.dreamform.mode') !== 'api' && $kirby->option('cache.pages.active') === true) {
			$append = '?x=';
		}

		return  $kirby->response()->redirect(
			($this->referer() ?? $this->site()->url()) . $append ?? ''
		);
	}

	/**
	 * Returns the current step of the submission
	 */
	public function currentStep(): int
	{
		return $this->state()->get('step')->toInt();
	}

	/**
	 * Advance the submission to the next step
	 */
	public function advanceStep(): static
	{
		$available = count($this->form()->steps());
		if ($this->state()->get('step')->value() >= $available) {
			return $this;
		}

		$state = $this->state()->toArray();
		$state['step'] = $state['step'] + 1;
		$this->content = $this->content()->update(['dreamform_state' => $state]);

		$this->saveSubmission();

		return $this;
	}

	/**
	 * Finish the submission and save it to the disk
	 */
	public function finish(bool $saveToDisk = true): static
	{
		// set partial state for showing "success"
		$state = $this->state()->toArray();
		$state['partial'] = false;
		$this->content = $this->content()->update(['dreamform_state' => $state]);

		$submission = App::instance()->apply(
			'dreamform.submit:after',
			['submission' => $this, 'form' => $this->form()],
			'submission'
		);

		if ($saveToDisk) {
			return $submission->saveSubmission();
		}

		return $submission;
	}

	/**
	 * Save the submission to the disk
	 */
	public function saveSubmission(): static
	{
		if (
			App::instance()->option('tobimori.dreamform.storeSubmissions', true) !== true
			|| !$this->form()->storeSubmissions()->toBool()
		) {
			return $this;
		}

		// elevate permissions to save the submission
		App::instance()->impersonate('kirby');
		$submission = $this->save($this->content()->toArray(), App::instance()?->languages()?->default()?->code() ?? null);
		App::instance()->impersonate();

		return $submission;
	}

	/**
	 * Returns a boolean whether the submission is finished
	 */
	public function isFinished(): bool
	{
		return !$this->state()->get('partial')->toBool();
	}

	/**
	 * Returns a boolean whether the submission was successful so far
	 */
	public function isSuccessful(): bool
	{
		return $this->state()->get('success')->toBool();
	}

	/**
	 * Returns the submission state as content object
	 */
	public function state(): Content
	{
		return $this->content()->get('dreamform_state')->toObject();
	}

	/** @var SubmissionPage|null */
	private static $session = null;

	/**
	 * Store submission in session for use with PRG pattern
	 */
	public function storeSession(): static
	{
		$kirby = App::instance();
		$mode = $kirby->option('tobimori.dreamform.mode', 'prg');
		if ($mode === 'api' || $mode === 'htmx' && Htmx::isHtmxRequest()) {
			return $this->storeSessionlessCache();
		}

		$kirby->session()->set(
			DreamForm::SESSION_KEY,
			// if the page exists on disk, we store the UUID only so we can save files since they can't be serialized
			$this->exists() ? $this->uuid()->toString() : $this
		);

		return static::$session = $this;
	}

	public function storeSessionlessCache(): static
	{
		$kirby = App::instance();
		if ($kirby->option('tobimori.dreamform.mode', 'prg') === 'prg' && !Htmx::isHtmxRequest()) {
			return $this->storeSession();
		}

		if (!$this->exists()) {
			$kirby->cache('tobimori.dreamform.sessionless')->set($this->uuid()->toString(), serialize($this), 60 * 24);
		}

		return static::$session = $this;
	}

	/**
	 * Returns the status of the submission
	 */
	public function status(): string
	{
		// TODO: 'draft' status for spam detection
		return $this->isFinished() ? 'listed' : 'unlisted';
	}

	/**
	 * Pull submission from session
	 */
	public static function fromSession(): SubmissionPage|null
	{
		$kirby = App::instance();
		$mode = $kirby->option('tobimori.dreamform.mode', 'prg');
		if ($mode === 'api' || $mode === 'htmx' && Htmx::isHtmxRequest()) {
			return static::fromSessionlessCache();
		}

		if (static::$session) {
			return static::$session;
		}

		$session = $kirby->session()->get(DreamForm::SESSION_KEY, null);
		if (is_string($session)) { // if the page exists on disk, we store the UUID only so we can save files
			$session = DreamForm::findPageOrDraftRecursive($session);
		}

		if (!($session instanceof SubmissionPage)) {
			return null;
		}

		static::$session = $session;

		// remove it from the session for subsequent loads
		if (
			static::$session && ( // if the session exists
				static::$session->isFinished() // & if the submission is finished
				|| (static::$session->currentStep() === 1 && !static::$session->isSuccessful()) // or if it's the first step and not successful
			)
		) {
			$kirby->session()->remove(DreamForm::SESSION_KEY);
		}

		return static::$session;
	}

	/**
	 * Get submission from sessionless cache
	 */
	public static function fromSessionlessCache(): SubmissionPage|null
	{
		$kirby = App::instance();
		if ($kirby->option('tobimori.dreamform.mode', 'prg') === 'prg' && !Htmx::isHtmxRequest()) {
			return static::fromSession();
		}

		if (static::$session) {
			return static::$session;
		}

		$raw = $kirby->request()->body()->get('dreamform:session');
		if (!$raw || $raw === 'null') {
			return null;
		}

		$id = Htmx::decrypt($raw);
		if (Str::startsWith($id, 'page://')) {
			static::$session = DreamForm::findPageOrDraftRecursive($id);
		}

		$cache = $kirby->cache('tobimori.dreamform.sessionless');
		$serialized = $cache->get($id);
		if ($serialized) {
			$submission = unserialize($serialized);
			if ($submission instanceof SubmissionPage) {
				static::$session = $submission;

				// remove it from the session for subsequent loads
				if (
					$submission->isFinished() // & if the submission is finished
					|| ($submission->currentStep() === 1 && !$submission->isSuccessful()) // or if it's the first step and not successful
				) {
					$cache->remove($id);
				}
			}
		}

		return static::$session;
	}

	/**
	 * Return the corresponding form page
	 */
	public function form(): FormPage
	{
		$page = $this->parent();

		if ($page->intendedTemplate()->name() !== 'form') {
			throw new InvalidArgumentException('[kirby-dreamform] SubmissionPage must be a child of a FormPage');
		}

		return $page;
	}

	/**
	 * Format the submission date as integer for sorting
	 */
	public function sortDate(): string
	{
		return $this->content()->get('dreamform_submitted')->toDate();
	}

	/**
	 * Format the submission date as title for use in the panel
	 */
	public function title(): Field
	{
		$date = new DateTime($this->content()->get('dreamform_submitted')->value());
		return new Field($this, 'title', IntlDateFormatter::formatObject($date, IntlDateFormatter::MEDIUM));
	}

	/**
	 * Downloads a gravatar image for the submission, to be used in the panel as page icon.
	 */
	public function gravatar(): File|null
	{
		if (!App::instance()->option('tobimori.dreamform.integrations.gravatar', true)) {
			return null;
		}

		// if we previously found no image for the entry, we don't need to check again
		if ($this->content()->get('dreamform_gravatar')->toBool()) {
			return null;
		}

		if ($this->file('gravatar.jpg')) {
			return $this->file('gravatar.jpg');
		}

		// Find the first email in the content
		foreach ($this->content()->data() as $value) {
			if (V::email($value)) {
				// trim & lowercase the email
				$value = Str::lower(Str::trim($value));
				$hash = hash('sha256', $value);


				$request = Remote::get("https://www.gravatar.com/avatar/{$hash}?d=404");
				if ($request->code() === 200) {
					// TODO: check if we need a temp file or if we can use the content directly?
					F::write($tmpPath = $this->root() . '/tmp.jpg', $request->content());
					$file = $this->createFile([
						'filename' => 'gravatar.jpg',
						'source' => $tmpPath,
						'parent' => $this
					]);
					F::remove($tmpPath);

					return $file;
				}
			}
		}

		$this->update([
			'dreamform_gravatar' => false
		]);

		return null;
	}

	/**
	 * Permissions check for the submission page
	 */
	public function isAccessible(): bool
	{
		if (!App::instance()->user()->role()->permissions()->for('tobimori.dreamform', 'accessSubmissions')) {
			return false;
		}

		return parent::isAccessible();
	}

	public function permissions(): SubmissionPermissions
	{
		return new SubmissionPermissions($this);
	}
}
