<?php
declare(strict_types=1);
namespace Drupal\islandora_bulk_delete\Controller;
use Drupal\Core\Database\Connection;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
final class BulkDeleteController extends ControllerBase {
  public function __construct(private readonly Connection $database) {}
  public static function create(ContainerInterface $c): static { return new static($c->get('database')); }
  public function job(int $job_id): array { $job=$this->database->select('islandora_bulk_delete_job','j')->fields('j')->condition('id',$job_id)->execute()->fetchAssoc(); if(!$job) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(); return ['summary'=>['#type'=>'table','#header'=>[$this->t('Metric'),$this->t('Value')],'#rows'=>[['Status',$job['status']],['Total matched',$job['total']],['Queued',$job['queued']],['Deleted',$job['deleted']],['Failed',$job['failed']],['Remaining',$job['pending']]]],'failures'=>['#type'=>'link','#title'=>$this->t('Download failure CSV'),'#url'=>\Drupal\Core\Url::fromRoute('islandora_bulk_delete.csv',['job_id'=>$job_id])],'#cache'=>['max-age'=>0]]; }
  public function csv(int $job_id): StreamedResponse { $db=$this->database; $r=new StreamedResponse(static function()use($db,$job_id){$o=fopen('php://output','wb');fputcsv($o,['nid','uuid','title','message','timestamp']);foreach($db->select('islandora_bulk_delete_failure','f')->fields('f')->condition('job_id',$job_id)->execute() as $row)fputcsv($o,[$row->nid,$row->uuid,$row->title,$row->message,date('c',(int)$row->created)]);fclose($o);});$r->headers->set('Content-Type','text/csv');return $r; }
}
