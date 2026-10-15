<?php

declare(strict_types=1);

namespace Drupal\heading_splitter\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\AdminContext;
use Drupal\heading_splitter\HeadingHighlighter;

/**
 * Highlights the start of headings in ConReg's public forms.
 *
 * ConReg writes its form headings as bare <h2> and <h3> tags around form
 * elements, so no text filter ever sees them. Forms are tagged in
 * hook_form_alter() and the headings are wrapped when the form is themed.
 *
 * The section titles of a form (the cards on the registration form) are the
 * legends of its top-level fieldsets, so those legends are highlighted too.
 * Fieldsets inside another fieldset are questions and groups within a card,
 * not headings, and are left alone.
 */
class FormHooks {

  /**
   * Namespace of ConReg's forms, and of its admin forms, which are skipped.
   */
  const CONREG_FORM_NAMESPACE = 'Drupal\\conreg\\';
  const CONREG_ADMIN_NAMESPACE = 'Drupal\\conreg\\Form\\Admin\\';

  public function __construct(
    protected HeadingHighlighter $highlighter,
    protected AdminContext $adminContext,
  ) {
  }

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object) {
      return;
    }
    $class = $form_object::class;
    if (str_starts_with($class, self::CONREG_FORM_NAMESPACE) && !str_starts_with($class, self::CONREG_ADMIN_NAMESPACE)) {
      $form['#heading_splitter_highlight_headings'] = TRUE;
      $this->highlightLegends($form);
    }
  }

  /**
   * Highlights the start of each top-level fieldset's legend.
   *
   * @param array $element
   *   A form element; its children are searched.
   * @param bool $inside_fieldset
   *   Whether $element is, or is inside, a fieldset.
   */
  protected function highlightLegends(array &$element, bool $inside_fieldset = FALSE): void {
    foreach (Element::children($element) as $key) {
      $is_fieldset = ($element[$key]['#type'] ?? '') === 'fieldset';
      if ($is_fieldset && !$inside_fieldset && isset($element[$key]['#title'])) {
        $title = (string) $element[$key]['#title'];
        // Plain text only; a title that already has markup is left as it is.
        if ($title !== '' && $title === strip_tags($title)) {
          $element[$key]['#title'] = $this->highlighter->highlightText(html_entity_decode($title, ENT_QUOTES | ENT_HTML5));
        }
      }
      $this->highlightLegends($element[$key], $inside_fieldset || $is_fieldset);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for form.html.twig.
   */
  #[Hook('preprocess_form')]
  public function preprocessForm(array &$variables): void {
    if (empty($variables['element']['#heading_splitter_highlight_headings']) || $this->adminContext->isAdminRoute()) {
      return;
    }
    if (isset($variables['children'])) {
      $variables['children'] = Markup::create($this->highlighter->highlight((string) $variables['children']));
    }
  }

}
