<?php

final class PhabricatorSearchManagementIndexWorkflow
  extends PhabricatorSearchManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('index')
      ->setSynopsis('Build or rebuild search indexes.')
      ->setExamples(
        "**index** D123\n".
        "**index** --type DREV\n".
        "**index** --all")
      ->setArguments(
        array(
          array(
            'name' => 'all',
            'help' => 'Reindex all documents.',
          ),
          array(
            'name'  => 'type',
            'param' => 'TYPE',
            'help'  => 'PHID type to reindex, like "TASK" or "DREV".',
          ),
          array(
            'name' => 'background',
            'help' => 'Instead of indexing in this process, queue tasks for '.
                      'the daemons. This can improve performance, but makes '.
                      'it more difficult to debug search indexing.',
          ),
          array(
            'name'      => 'objects',
            'wildcard'  => true,
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $console = PhutilConsole::getConsole();

    $is_all = $args->getArg('all');
    $is_type = $args->getArg('type');

    $obj_names = $args->getArg('objects');

    if ($obj_names && ($is_all || $is_type)) {
      throw new PhutilArgumentUsageException(
        "You can not name objects to index alongside the '--all' or '--type' ".
        "flags.");
    } else if (!$obj_names && !($is_all || $is_type)) {
      throw new PhutilArgumentUsageException(
        "Provide one of '--all', '--type' or a list of object names.");
    }

    if ($obj_names) {
      $phids = $this->loadPHIDsByNames($obj_names);
    } else {
      $phids = $this->loadPHIDsByTypes($is_type);
    }

    if (!$phids) {
      throw new PhutilArgumentUsageException('Nothing to index!');
    }

    if ($args->getArg('background')) {
      $is_background = true;
    } else {
      PhabricatorWorker::setRunAllTasksInProcess(true);
      $is_background = false;
    }

    if (!$is_background) {
      $console->writeOut(
        "%s\n",
        pht(
          'Run this workflow with "--background" to queue tasks for the '.
          'daemon workers.'));
    }

    $groups = phid_group_by_type($phids);
    foreach ($groups as $group_type => $group) {
      $console->writeOut(
        "%s\n",
        pht('Indexing %d object(s) of type %s.', count($group), $group_type));
    }

    $bar = id(new PhutilConsoleProgressBar())
      ->setTotal(count($phids));

    $indexer = new PhabricatorSearchIndexer();
    foreach ($phids as $phid) {
      $indexer->queueDocumentForIndexing($phid);
      $bar->update(1);
    }

    $bar->done();
  }

  private function loadPHIDsByNames(array $names) {
    $query = id(new PhabricatorObjectQuery())
      ->setViewer($this->getViewer())
      ->withNames($names);
    $query->execute();
    $objects = $query->getNamedResults();

    foreach ($names as $name) {
      if (empty($objects[$name])) {
        throw new PhutilArgumentUsageException(
          "'{$name}' is not the name of a known object.");
      }
    }

    return mpull($objects, 'getPHID');
  }

  private function loadPHIDsByTypes($type) {
    $indexers = id(new PhutilSymbolLoader())
      ->setAncestorClass('PhabricatorSearchDocumentIndexer')
      ->loadObjects();

    $phids = array();
    foreach ($indexers as $indexer) {
      $indexer_phid = $indexer->getIndexableObject()->generatePHID();
      $indexer_type = phid_get_type($indexer_phid);

      if ($type && strcasecmp($indexer_type, $type)) {
        continue;
      }

      $iterator = $indexer->getIndexIterator();
      foreach ($iterator as $object) {
        $phids[] = $object->getPHID();
      }
    }

    return $phids;
  }

}
