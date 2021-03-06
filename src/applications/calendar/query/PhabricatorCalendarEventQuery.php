<?php

final class PhabricatorCalendarEventQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $rangeBegin;
  private $rangeEnd;
  private $invitedPHIDs;
  private $creatorPHIDs;
  private $isCancelled;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withDateRange($begin, $end) {
    $this->rangeBegin = $begin;
    $this->rangeEnd = $end;
    return $this;
  }

  public function withInvitedPHIDs(array $phids) {
    $this->invitedPHIDs = $phids;
    return $this;
  }

  public function withCreatorPHIDs(array $phids) {
    $this->creatorPHIDs = $phids;
    return $this;
  }

  public function withIsCancelled($is_cancelled) {
    $this->isCancelled = $is_cancelled;
    return $this;
  }

  protected function loadPage() {
    $table = new PhabricatorCalendarEvent();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T %Q %Q %Q',
      $table->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    return $table->loadAllFromArray($data);
  }

  protected function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'phid IN (%Ls)',
        $this->phids);
    }

    if ($this->rangeBegin) {
      $where[] = qsprintf(
        $conn_r,
        'dateTo >= %d',
        $this->rangeBegin);
    }

    if ($this->rangeEnd) {
      $where[] = qsprintf(
        $conn_r,
        'dateFrom <= %d',
        $this->rangeEnd);
    }

    // TODO: Currently, the creator is always the only invitee, but you can
    // query them separately since this won't always be true.

    if ($this->invitedPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'userPHID IN (%Ls)',
        $this->invitedPHIDs);
    }

    if ($this->creatorPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'userPHID IN (%Ls)',
        $this->creatorPHIDs);
    }

    if ($this->isCancelled !== null) {
      $where[] = qsprintf(
        $conn_r,
        'isCancelled = %d',
        (int)$this->isCancelled);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorCalendarApplication';
  }


  protected function willFilterPage(array $events) {
    $phids = array();

    foreach ($events as $event) {
      $phids[] = $event->getPHID();
    }

    $invitees = id(new PhabricatorCalendarEventInviteeQuery())
      ->setViewer($this->getViewer())
      ->withEventPHIDs($phids)
      ->execute();
    $invitees = mgroup($invitees, 'getEventPHID');

    foreach ($events as $event) {
      $event_invitees = idx($invitees, $event->getPHID(), array());
      $event->attachInvitees($event_invitees);
    }

    return $events;
  }

}
