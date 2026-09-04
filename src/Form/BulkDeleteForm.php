<?php
declare(strict_types=1);
namespace Drupal\islandora_bulk_delete\Form;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\islandora_bulk_delete\Service\BulkDeleteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
final class BulkDeleteForm extends ConfirmFormBase {
  public function __construct(private readonly BulkDeleteManager $manager) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('islandora_bulk_delete.manager')); }
  public function getFormId(): string { return 'islandora_bulk_delete_form'; }
  public function getQuestion() { return $this->t('Start destructive deletion of all matching records?'); }
  public function getCancelUrl(): Url { return Url::fromRoute('system.admin_content'); }
  public function buildForm(array $form, ?FormStateInterface $state = NULL): array {
    $state ??= new FormState();
    $form['status'] = ['#type'=>'select','#title'=>$this->t('Publication status'),'#options'=>[''=>$this->t('- Any -'),'1'=>$this->t('Published'),'0'=>$this->t('Unpublished')]];
    $form['type'] = ['#type'=>'textfield','#title'=>$this->t('Content type machine name')];
    $form['collection'] = ['#type'=>'entity_autocomplete','#title'=>$this->t('Collection node'),'#target_type'=>'node'];
    $form['taxonomy_field'] = ['#type'=>'textfield','#title'=>$this->t('Taxonomy field name (optional)'),'#description'=>$this->t('Example: field_category')];
    $form['taxonomy_term'] = ['#type'=>'entity_autocomplete','#title'=>$this->t('Taxonomy term (optional)'),'#target_type'=>'taxonomy_term'];
    foreach (['created_from'=>'Created from','created_to'=>'Created to','modified_from'=>'Modified from','modified_to'=>'Modified to'] as $key=>$label) $form[$key]=['#type'=>'date','#title'=>$this->t($label)];
    $form['notice'] = ['#type' => 'item', '#markup' => $this->t('Matching records: @count. Deletion is permanent and runs asynchronously.', ['@count' => $this->manager->countMatches(array_map(static fn($v) => $v ?? '', $state->getUserInput()))])];
    $form['confirm'] = ['#type' => 'checkbox', '#title' => $this->t('I understand this will permanently delete all matching repository records.'), '#required' => TRUE];
    $form['actions']['submit']=['#type'=>'submit','#value'=>$this->t('Review matching records')];
    return $form;
  }
  public function submitForm(array &$form, FormStateInterface $state): void {
    $filters = []; foreach (['status','type','collection','taxonomy_field','taxonomy_term','created_from','created_to','modified_from','modified_to'] as $key) $filters[$key] = $state->getValue($key);
    $job = $this->manager->start($filters); $row = $this->manager->load($job); $this->messenger()->addStatus($this->t('Deletion job started. @count records queued.', ['@count' => $row['queued'] ?? 0])); $state->setRedirect('islandora_bulk_delete.job', ['job_id'=>$job]);
  }
}
