<?php
namespace ProcessWire;

class PageAccessReleasetime extends WireData implements Module {

	const module_tags = 'Releasetime';
	const fieldnames = array('releasetime_start_activate', 'releasetime_start', 'releasetime_end_activate', 'releasetime_end');
	const permissionname = 'page-view-not-released';

	public static function getModuleInfo() {
		return array(
			'title' => 'Page Access Releasetime',
			'version' => '1.0.0',
			'summary' => 'Enables you to set a start- and end-time for the release of pages. Prevents unreleased pages from being displayed.',
			'singular' => true,
			'autoload' => true,
			'icon' => 'hourglass-half',
			'requires' => array('PHP>=5.5.0', 'ProcessWire>=3.0.0'),
		);
	}

	public function ___install(){
		$flags = Field::flagGlobal + Field::flagSystem + Field::flagAccessAPI + Field::flagAutojoin;

		$field = new Field();
		$field->type = $this->modules->get("FieldtypeCheckbox");
		$field->name = 'releasetime_start_activate';
		$field->label = 'Activate Releasetime from?';
		$field->tags = self::module_tags;
		$field->flags = $flags;
		$field->save();

		$field = new Field();
		$field->type = $this->modules->get("FieldtypeDatetime");
		$field->name = 'releasetime_start';
		$field->label = 'Release from:';
		$field->tags = self::module_tags;
		$field->flags = $flags;
		$field->dateInputFormat = 'd.m.Y';
		$field->timeInputFormat = 'H:i:s';
		$field->datepicker = true;
		$field->defaultToday = true;
		$field->showIf = 'releasetime_start_activate=1';
		$field->requiredIf = 'releasetime_start_activate=1';
		$field->save();

		$field = new Field();
		$field->type = $this->modules->get("FieldtypeCheckbox");
		$field->name = 'releasetime_end_activate';
		$field->label = 'Activate Releasetime to?';
		$field->tags = self::module_tags;
		$field->flags = $flags;
		$field->save();

		$field = new Field();
		$field->type = $this->modules->get("FieldtypeDatetime");
		$field->name = 'releasetime_end';
		$field->label = 'Release to:';
		$field->tags = self::module_tags;
		$field->flags = $flags;
		$field->dateInputFormat = 'd.m.Y';
		$field->timeInputFormat = 'H:i:s';
		$field->datepicker = true;
		$field->defaultToday = true;
		$field->showIf = 'releasetime_end_activate=1';
		$field->requiredIf = 'releasetime_end_activate=1';
		$field->save();

		$permission = $this->wire('permissions')->add(self::permissionname);
		$permission->title = 'Can see pages that are not yet released.';
		$permission->save();
	}

	public function ___uninstall(){
		foreach(self::fieldnames as $fieldname){
			$feld = $this->wire('fields')->get($fieldname);
			if(!($feld instanceof Field) || $feld->name != $fieldname) continue;

			$feld->flags = Field::flagSystemOverride;
			$feld->flags = 0;
			$feld->save();

			foreach($this->wire('templates') as $template){
				if(!$template->hasField($fieldname)) continue;
				$template->fieldgroup->remove($feld);
				$template->fieldgroup->save();
			}

			$this->wire('fields')->delete($feld);
		}
	}

	public function init() {
		// Move releasetime-fields to settings-tab
		$this->addHookAfter("ProcessPageEdit::buildForm", $this, "moveFieldToSettings");

		// Prevent unreleased pagse from being viewed
		$this->addHook('Page::viewable', $this, 'hookPageViewable');

		// Manage access to files ($config->pagefileSecure has to be true)
		$this->addHookAfter('Page::isPublic', $this, 'hookPageIsPublic');
		$this->addHookBefore('ProcessPageView::sendFile', $this, 'hookProcessPageViewSendFile');

		// TODO: Can we manipulate $pages->find() to exclude unreleased pages?
		// $this->addHookBefore('Pages::find', $this, 'beforePagesFind');
	}

	/**
	 * Hook for Page::viewable() or Page::viewable($user) method
	 *
	 * Is the page viewable by the current user? (or specified user)
	 * Optionally specify $user object to hook as first argument to check for a specific User.
	 * Optionally specify a field name (or Field object) to hook as first argument to check for specific field.
	 * Optionally specify boolean false as first or second argument to hook to bypass template filename check.
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookPageViewable($event) {
		$page = $event->object;
		$viewable = $event->return;

		if($viewable){
			// If the page would be viewable, additionally check Releasetime and User-Permission
			$viewable = $this->canUserSee($page);
		}
		$event->return = $viewable;
	}

	/**
	 * if Page::isPublic() returns false a prefix (-) will be added to the name of the assets directory
	 * the directory is not accessible directly anymore
	 *
	 * @see https://processwire.com/talk/topic/15622-pagefilesecure-and-pageispublic-hook-not-working/
	 */
	public function hookPageIsPublic($e) {
		$page = $e->object;
		if($e->return && $this->isReleaseTimeSet($page)) {
			$e->return = false;
		}
	}

	/**
	 * ProcessPageView::sendFile() is called only if the file is not directly accessible
	 * if this function is called AND the page is not public it passthru the protected file path (.htaccess) by default
	 * therefore we need this hook too
	 *
	 * @see https://processwire.com/talk/topic/15622-pagefilesecure-and-pageispublic-hook-not-working/
	 */
	public function hookProcessPageViewSendFile($e) {
		$page = $e->arguments[0];
		if(!$this->canUserSee($page)) {
			throw new Wire404Exception('File not found');
		}
	}

	/**
	 * Checks wether a page is unlocked or the current user has the permission "page-view-not-released" which enables them to see unreleased pages.
	 * @param  Page    $page
	 * @param  User|boolean $user  if no valid user is passed the current user will be used.
	 * @return boolean
	 */
	public function canUserSee(Page $page, $user = false){
		if(!$user instanceof User || !$user->id) $user = $this->wire('user');
		if($user->isSuperuser() || $user->hasPermission(self::permissionname)) return true;

		if(!$this->isReleased($page)) return false;

		return true;
	}

	/**
	 * Checks if a page and its parents are released yet.
	 * @param  Page    $page
	 * @return boolean
	 */
	public function isReleased(Page $page){
		if(!$this->isReleasedSingle($page)) return false;

		foreach($page->parents as $parentPage){
			if(!($parentPage instanceof Page) || !$parentPage->id) continue;
			if(!$this->isReleasedSingle($parentPage)) return false;
		}

		return true;
	}

	/**
	 * Checks, if a single page is released.
	 * @param  Page    $page
	 * @return boolean
	 */
	public function isReleasedSingle(Page $page){
		if($page->template->hasField('releasetime_start') && (!$page->template->hasField('releasetime_start_activate') || $page->releasetime_start_activate == true) && $page->releasetime_start > time()){
			return false;
		}else if($page->template->hasField('releasetime_end') && (!$page->template->hasField('releasetime_end_activate') || $page->releasetime_end_activate == true) && $page->releasetime_end < time()){
			return false;
		}

		return true;
	}

	/**
	 * Does the page have an activated releasetime-field?
	 * @param  Page    $page
	 * @return boolean
	 */
	public function isReleaseTimeSet(Page $page){
		if($page->template->hasField('releasetime_start') && (!$page->template->hasField('releasetime_start_activate') || $page->releasetime_start_activate == true)){
			return true;
		}

		if($page->template->hasField('releasetime_end') && (!$page->template->hasField('releasetime_end_activate') || $page->releasetime_end_activate == true)){
			return true;
		}

		return false;
	}

	/**
	 * Moves the releasetime-fields to the settings-tab
	 * @param  HookEvent $event
	 */
	public function moveFieldToSettings(HookEvent $event) {
		$form = $event->return;

		foreach(self::fieldnames as $fieldname){
			$field = $form->find("name=".$fieldname)->first();

			if($field) {
				$settings = $form->find("id=ProcessPageEditSettings")->first();

				if($settings) {
					$form->remove($field);
					$settings->append($field);
				}
			}
		}
	}

}