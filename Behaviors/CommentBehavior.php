<?php
namespace exface\Core\Behaviors;

use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Behaviors\BehaviorConfigurationError;
use exface\Core\CommonLogic\DataSheets\DataColumn;
use exface\Core\CommonLogic\Traits\ICanBypassDataAuthorizationTrait;
use exface\Core\CommonLogic\Traits\SendMessagesFromDataTrait;
use exface\Core\Events\DataSheet\OnCreateDataEvent;
use exface\Core\Events\DataSheet\OnUpdateDataEvent;
use exface\Core\Events\DataSheet\OnReadDataEvent;
use exface\Core\Events\DataSheet\OnBeforeDeleteDataEvent;
use exface\Core\Events\Workbench\OnBeforeStopEvent;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Model\BehaviorInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

/**
 * Makes easy to manage comments widgets with the same table structure.
 * 
 * 
 * * ## Examples
 * 
 * ### Simple comments
 * 
 * ```
 * {
 *    "comment_id_attribute": "UID",
 *    "comment_created_date_attribute": "CREATED_ON",
 *    "comment_edited_date_attribute": "MODIFY_ON",
 *    "comment_author_id_attribute": "CREATED_BY",
 *    "comment_author_attribute": "CREATED_BY__FULL_NAME",
 *    "comment_content_attribute": "TITLE",
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 *
 */
class CommentBehavior extends AbstractBehavior
{
    use ICanBypassDataAuthorizationTrait;
    use SendMessagesFromDataTrait;

    private $commentContentAttributeAlias = null;
    
    private $commentCreatedDateAttributeAlias = null;
    
    private $commentEditedDateAttributeAlias = null;
    
    private $commentAuthorAttributeAlias = null;
    
    private $commentIdAttributeAlias = null;
    
    private $commentAuthorIdAttributeAlias = null;
    
    private $timeModifiedAttributeAlias = null;
    
    private $allowedFileExtensions = [];
    
    private $allowedMimeTypes = [];
    
    private $maxFilenameLength = 255;
    
    private $maxFileSizeMb = null;

    private $imageResizeToMaxSide = null;

    private $imageResizeQuality = null;

    private $mentionObjectAlias = null;

    private $mentionCommentUidAttributeAlias = null;

    private $mentionUserUidAttributeAlias = null;

    private $mentionSearchRoles = [];

    private $mentionNotificationPageAlias = null;

    private $mentionNotificationFilterAttribute = null;

    private $mentionNotificationFilterValueAttributeAlias = null;

    private $pendingMentionNotifications = [];

    private $stopListenerRegistered = false;

    /**
     * Registers mention persistence on the comment lifecycle and ping enrichment on reads.
     *
     * @return BehaviorInterface
     */
    protected function registerEventListeners() : BehaviorInterface
    {
        parent::registerEventListeners();
        if ($this->hasMentionConfiguration()) {
            $eventManager = $this->getWorkbench()->eventManager();
            $eventManager->addListener(OnCreateDataEvent::getEventName(), [$this, 'onCommentsCreated'], $this->getPriority());
            $eventManager->addListener(OnUpdateDataEvent::getEventName(), [$this, 'onCommentsUpdated'], $this->getPriority());
            $eventManager->addListener(OnReadDataEvent::getEventName(), [$this, 'onCommentsRead'], $this->getPriority());
            $eventManager->addListener(OnBeforeDeleteDataEvent::getEventName(), [$this, 'onCommentsDeleting'], $this->getPriority());
        }
        return $this;
    }

    /**
     * Removes mention lifecycle hooks when the behavior is disabled or unloaded.
     *
     * @return BehaviorInterface
     */
    protected function unregisterEventListeners() : BehaviorInterface
    {
        $eventManager = $this->getWorkbench()->eventManager();
        $eventManager->removeListener(OnCreateDataEvent::getEventName(), [$this, 'onCommentsCreated']);
        $eventManager->removeListener(OnUpdateDataEvent::getEventName(), [$this, 'onCommentsUpdated']);
        $eventManager->removeListener(OnReadDataEvent::getEventName(), [$this, 'onCommentsRead']);
        $eventManager->removeListener(OnBeforeDeleteDataEvent::getEventName(), [$this, 'onCommentsDeleting']);
        if ($this->stopListenerRegistered) {
            $eventManager->removeListener(OnBeforeStopEvent::getEventName(), [$this, 'sendPendingMentionNotifications']);
            $this->stopListenerRegistered = false;
        }
        return $this;
    }

    /**
     * Reports whether this behavior has enough storage configuration to resolve and persist mentions.
     *
     * @return bool
     */
    public function hasMentionConfiguration() : bool
    {
        return $this->mentionObjectAlias !== null
            && $this->mentionCommentUidAttributeAlias !== null
            && $this->mentionUserUidAttributeAlias !== null
            && $this->commentContentAttributeAlias !== null
            && $this->commentAuthorIdAttributeAlias !== null
            && ($this->commentIdAttributeAlias !== null || $this->getObject()->hasUidAttribute());
    }

    /**
     * Fails before rendering or searching when mention storage or notification mappings cannot be resolved.
     *
     * @return void
     */
    public function assertMentionConfiguration() : void
    {
        $commentObject = $this->getObject();
        $commentIdAlias = $this->commentIdAttributeAlias;
        if ($commentIdAlias === null && $commentObject->hasUidAttribute()) {
            $commentIdAlias = $commentObject->getUidAttributeAlias();
        }
        if (! $this->hasMentionConfiguration()
            || $commentIdAlias === null
            || ! $commentObject->hasAttribute($commentIdAlias)
            || ! $commentObject->hasAttribute($this->commentContentAttributeAlias)
            || ! $commentObject->hasAttribute($this->commentAuthorIdAttributeAlias)) {
            throw new RuntimeException('CommentsFeed mentions require valid comment ID, content, author, and mention-storage attributes on CommentBehavior.');
        }

        $mentionObject = $this->getMentionObject();
        if (! $mentionObject->hasAttribute($this->mentionCommentUidAttributeAlias)
            || ! $mentionObject->hasAttribute($this->mentionUserUidAttributeAlias)) {
            throw new RuntimeException('CommentsFeed mentions require valid comment and user UID attributes on the configured mention object.');
        }

        $notificationConfigured = $this->mentionNotificationPageAlias !== null
            || $this->mentionNotificationFilterAttribute !== null
            || $this->mentionNotificationFilterValueAttributeAlias !== null;
        if ($notificationConfigured
            && ($this->mentionNotificationPageAlias === null
                || trim($this->mentionNotificationPageAlias) === ''
                || $this->mentionNotificationFilterAttribute === null
                || trim($this->mentionNotificationFilterAttribute) === ''
                || $this->mentionNotificationFilterValueAttributeAlias === null
                || trim($this->mentionNotificationFilterValueAttributeAlias) === ''
                || ! $commentObject->hasAttribute($this->mentionNotificationFilterValueAttributeAlias))) {
            throw new RuntimeException('Mention notification navigation requires a page, target filter attribute, and valid comment filter-value attribute.');
        }
    }

    /**
     * Resolves the separately configured mention table for persistence and batched reads.
     *
     * @return MetaObjectInterface
     */
    public function getMentionObject() : MetaObjectInterface
    {
        return MetaObjectFactory::createFromString($this->getWorkbench(), $this->mentionObjectAlias);
    }

    /**
     * Resolves the mention table's comment reference for queries and synchronization.
     *
     * @return MetaAttributeInterface
     */
    public function getMentionCommentUidAttribute() : MetaAttributeInterface
    {
        return $this->getMentionObject()->getAttribute($this->mentionCommentUidAttributeAlias);
    }

    /**
     * Resolves the mention table's user reference for validation and ping rendering.
     *
     * @return MetaAttributeInterface
     */
    public function getMentionUserUidAttribute() : MetaAttributeInterface
    {
        return $this->getMentionObject()->getAttribute($this->mentionUserUidAttributeAlias);
    }

    /**
     * Sets the separate object used to persist comment-to-user mention rows.
     *
     * @uxon-property mention_object
     * @uxon-type metamodel:object
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setMentionObject(string $value) : CommentBehavior
    {
        $this->mentionObjectAlias = $value;
        return $this;
    }

    /**
     * Sets the mention-object attribute that references the comment UID.
     *
     * @uxon-property mention_comment_uid_attribute
     * @uxon-type metamodel:attribute
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setMentionCommentUidAttribute(string $value) : CommentBehavior
    {
        $this->mentionCommentUidAttributeAlias = $value;
        return $this;
    }

    /**
     * Sets the mention-object attribute that references the mentioned user's UID.
     *
     * @uxon-property mention_user_uid_attribute
     * @uxon-type metamodel:attribute
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setMentionUserUidAttribute(string $value) : CommentBehavior
    {
        $this->mentionUserUidAttributeAlias = $value;
        return $this;
    }

    /**
     * Restricts suggestions and accepted mentions to these role aliases when configured.
     *
     * @uxon-property mention_search_roles
     * @uxon-type string[]
     *
     * @param UxonObject $value
     * @return CommentBehavior
     */
    protected function setMentionSearchRoles(UxonObject $value) : CommentBehavior
    {
        $this->mentionSearchRoles = array_values(array_filter(
            $value->toArray(),
            static function ($role) : bool {
                return \is_string($role) && trim($role) !== '';
            }
        ));
        return $this;
    }

    /**
     * Sets the page opened from a notification about a new mention.
     *
     * @uxon-property mention_notification_page
     * @uxon-type metamodel:page
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setMentionNotificationPage(string $value) : CommentBehavior
    {
        $this->mentionNotificationPageAlias = $value;
        return $this;
    }

    /**
     * Sets the destination page filter attribute that receives the comment's configured filter value.
     *
     * @uxon-property mention_notification_filter_attribute
     * @uxon-type string
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setMentionNotificationFilterAttribute(string $value) : CommentBehavior
    {
        $this->mentionNotificationFilterAttribute = $value;
        return $this;
    }

    /**
     * Sets the comment attribute supplying the destination page filter value.
     *
     * @uxon-property mention_notification_filter_value_attribute
     * @uxon-type metamodel:attribute
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setMentionNotificationFilterValueAttribute(string $value) : CommentBehavior
    {
        $this->mentionNotificationFilterValueAttributeAlias = $value;
        return $this;
    }

    /**
     * Searches only users belonging to the configured roles or the comment object's application roles.
     *
     * @param string $term
     * @return array
     */
    public function searchMentionUsers(string $term) : array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        return $this->loadMentionUsers(null, $term, 10);
    }

    /**
     * Resolves only allowed UIDs so forged mention tokens cannot create arbitrary mention rows.
     *
     * @param string[] $uids
     * @return array
     */
    protected function getAllowedMentionUsers(array $uids) : array
    {
        if (empty($uids)) {
            return [];
        }
        return $this->loadMentionUsers(array_values(array_unique($uids)), null, null);
    }

    /**
     * Loads allowed user names in batches, keeping user-table access inside this scoped behavior.
     *
     * @param string[]|null $uidFilter
     * @param string|null $term
     * @param int|null $limit
     * @return array
     */
    protected function loadMentionUsers(?array $uidFilter, ?string $term, ?int $limit) : array
    {
        return $this->bypassDataAuthorization(function () use ($uidFilter, $term, $limit) : array {
            $workbench = $this->getWorkbench();
            $roleObject = MetaObjectFactory::createFromString($workbench, 'exface.Core.USER_ROLE');
            $roleSheet = DataSheetFactory::createFromObject($roleObject);
            $roleSheet->getColumns()->addFromAttribute($roleObject->getUidAttribute());
            $roleSheet->getColumns()->addFromAttribute($roleObject->getAttribute('ALIAS_WITH_NS'));
            if ($this->mentionSearchRoles) {
                $roleSheet->getFilters()->addConditionFromString(
                    'ALIAS_WITH_NS',
                    implode(',', $this->mentionSearchRoles),
                    ComparatorDataType::IN
                );
            } else {
                $roleSheet->getFilters()->addConditionFromString(
                    'APP',
                    $this->getObject()->getApp()->getUid(),
                    ComparatorDataType::EQUALS
                );
            }
            $roleSheet->dataRead();

            $roleUids = $roleSheet->getColumns()->getByAttribute($roleObject->getUidAttribute())->getValues(false);
            if (empty($roleUids)) {
                return [];
            }

            $assignmentObject = MetaObjectFactory::createFromString($workbench, 'exface.Core.USER_ROLE_USERS');
            $assignmentSheet = DataSheetFactory::createFromObject($assignmentObject);
            $assignmentSheet->getColumns()->addFromAttribute($assignmentObject->getAttribute('USER'));
            $assignmentSheet->getFilters()->addConditionFromString(
                'USER_ROLE',
                implode(',', $roleUids),
                ComparatorDataType::IN
            );
            if ($uidFilter !== null) {
                $assignmentSheet->getFilters()->addConditionFromString(
                    'USER',
                    implode(',', $uidFilter),
                    ComparatorDataType::IN
                );
            }
            $assignmentSheet->dataRead();
            $allowedUids = array_values(array_unique($assignmentSheet->getColumns()->getByAttribute($assignmentObject->getAttribute('USER'))->getValues(false)));
            if (empty($allowedUids)) {
                return [];
            }

            $userObject = MetaObjectFactory::createFromString($workbench, 'exface.Core.USER');
            $userSheet = DataSheetFactory::createFromObject($userObject);
            $userSheet->getColumns()->addFromAttribute($userObject->getUidAttribute());
            $userSheet->getColumns()->addFromAttribute($userObject->getAttribute('FULL_NAME'));
            $userSheet->getFilters()->addConditionFromString(
                $userObject->getUidAttributeAlias(),
                implode(',', $allowedUids),
                ComparatorDataType::IN
            );
            $userSheet->getFilters()->addConditionFromString('DISABLED_FLAG', '0', ComparatorDataType::EQUALS);
            if ($term !== null) {
                $userSheet->getFilters()->addConditionFromString('FULL_NAME', $term, ComparatorDataType::IS);
            }
            if ($limit !== null) {
                $userSheet->setRowsLimit($limit);
            }
            $userSheet->dataRead();

            $uidColumn = $userSheet->getColumns()->getByAttribute($userObject->getUidAttribute());
            $nameColumn = $userSheet->getColumns()->getByAttribute($userObject->getAttribute('FULL_NAME'));
            $users = [];
            foreach ($userSheet->getRows() as $row) {
                $uid = $row[$uidColumn->getName()] ?? null;
                $name = $row[$nameColumn->getName()] ?? null;
                if ($uid === null || $name === null) {
                    continue;
                }
                $users[strtolower((string) $uid)] = [
                    'uid' => (string) $uid,
                    'fullname' => htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                ];
            }
            return $users;
        });
    }

    /**
     * Adds one ping map to every comment row using a single mention-table read and a single name read.
     *
     * @param OnReadDataEvent $event
     * @return void
     */
    public function onCommentsRead(OnReadDataEvent $event) : void
    {
        $sheet = $event->getDataSheet();
        if (! $sheet->getMetaObject()->isExactly($this->getObject()) || ! $this->hasMentionConfiguration()) {
            return;
        }
        if ($sheet->getColumns()->getByExpression('pings') === false && ! $this->isCommentsFeedDataSheet($sheet)) {
            return;
        }
        $commentIdColumn = $sheet->getColumns()->getByAttribute($this->getCommentIdAttribute());
        if ($commentIdColumn === false || $sheet->isEmpty()) {
            return;
        }
        $contentColumn = $sheet->getColumns()->getByAttribute($this->getCommentContentAttribute());
        $commentUids = array_values(array_unique(array_filter($commentIdColumn->getValues(false))));
        $mentionRows = $this->readMentionRows($commentUids);
        $mentionCommentColumn = $mentionRows->getColumns()->getByAttribute($this->getMentionCommentUidAttribute());
        $mentionUserColumn = $mentionRows->getColumns()->getByAttribute($this->getMentionUserUidAttribute());
        $usersByUid = $this->getUserNamesByUid(array_values(array_unique($mentionUserColumn->getValues(false))));
        $pingsByComment = [];
        foreach ($mentionRows->getRows() as $row) {
            $commentUid = (string) ($row[$mentionCommentColumn->getName()] ?? '');
            $userUid = strtolower((string) ($row[$mentionUserColumn->getName()] ?? ''));
            if (isset($usersByUid[$userUid])) {
                $pingsByComment[$commentUid][$usersByUid[$userUid]['uid']] = $usersByUid[$userUid]['fullname'];
            }
        }
        foreach ($sheet->getRows() as $row) {
            $commentUid = (string) ($row[$commentIdColumn->getName()] ?? '');
            $pingMap = $pingsByComment[$commentUid] ?? [];
            $rowData = [
                $commentIdColumn->getName() => $commentUid,
                'pings' => $pingMap
            ];
            if ($contentColumn !== false) {
                $content = htmlspecialchars((string) ($row[$contentColumn->getName()] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $content = preg_replace_callback(
                    '/(?<![A-Za-z0-9_])@(0x[A-Za-z0-9_-]+)(?![A-Za-z0-9_])/i',
                    static function (array $matches) use ($pingMap) : string {
                        foreach ($pingMap as $uid => $fullname) {
                            if (strcasecmp($uid, $matches[1]) === 0) {
                                return $matches[0];
                            }
                        }
                        return '@mentioned user';
                    },
                    $content
                );
                $rowData[$contentColumn->getName()] = $content;
            }
            $sheet->addRow($rowData, true, false);
        }
    }

    /**
     * Recognizes feed reads by their full configured field mapping because data-read events do not carry the triggering widget.
     *
     * @param DataSheetInterface $sheet
     * @return bool
     */
    protected function isCommentsFeedDataSheet(DataSheetInterface $sheet) : bool
    {
        $attributes = [
            $this->getCommentIdAttribute(),
            $this->getCommentCreatedDateAttribute(),
            $this->getCommentEditedDateAttribute(),
            $this->getCommentContentAttribute(),
            $this->getCommentAuthorIdAttribute(),
            $this->getCommentAuthorAttribute()
        ];
        foreach ($attributes as $attribute) {
            if ($attribute === null || $sheet->getColumns()->getByAttribute($attribute) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Persists mentions after a new comment has been written, reusing the comment's transaction.
     *
     * @param OnCreateDataEvent $event
     * @return void
     */
    public function onCommentsCreated(OnCreateDataEvent $event) : void
    {
        if ($event->getDataSheet()->getMetaObject()->isExactly($this->getObject())) {
            $this->synchronizeMentions($event->getDataSheet(), $event->getTransaction());
        }
    }

    /**
     * Diffs and synchronizes mentions after a comment update in the same transaction.
     *
     * @param OnUpdateDataEvent $event
     * @return void
     */
    public function onCommentsUpdated(OnUpdateDataEvent $event) : void
    {
        if ($event->getDataSheet()->getMetaObject()->isExactly($this->getObject())) {
            $this->synchronizeMentions($event->getDataSheet(), $event->getTransaction());
        }
    }

    /**
     * Removes mention rows in the same transaction before their comment is deleted.
     *
     * @param OnBeforeDeleteDataEvent $event
     * @return void
     */
    public function onCommentsDeleting(OnBeforeDeleteDataEvent $event) : void
    {
        $comments = $event->getDataSheet();
        if (! $comments->getMetaObject()->isExactly($this->getObject())) {
            return;
        }
        $idColumn = $comments->getColumns()->getByAttribute($this->getCommentIdAttribute());
        if ($idColumn === false) {
            return;
        }
        $commentUids = array_values(array_unique(array_filter($idColumn->getValues(false))));
        if (empty($commentUids)) {
            return;
        }
        $mentions = $this->readMentionRows($commentUids);
        if (! $mentions->isEmpty()) {
            $mentions->dataDelete($event->getTransaction());
        }
    }

    /**
     * Reads mention rows for a set of comment UIDs in one batch for read enrichment and synchronization.
     *
     * @param string[] $commentUids
     * @return DataSheetInterface
     */
    protected function readMentionRows(array $commentUids) : DataSheetInterface
    {
        return $this->bypassDataAuthorization(function () use ($commentUids) : DataSheetInterface {
            $object = $this->getMentionObject();
            $sheet = DataSheetFactory::createFromObject($object);
            $sheet->getColumns()->addFromAttribute($object->getUidAttribute());
            $sheet->getColumns()->addFromAttribute($this->getMentionCommentUidAttribute());
            $sheet->getColumns()->addFromAttribute($this->getMentionUserUidAttribute());
            if (! empty($commentUids)) {
                $sheet->getFilters()->addConditionFromString(
                    $this->getMentionCommentUidAttribute()->getAliasWithRelationPath(),
                    implode(',', $commentUids),
                    ComparatorDataType::IN
                );
                $sheet->dataRead();
            }
            return $sheet;
        });
    }

    /**
     * Reads names for all mention UIDs at once so comments never expose their raw UID tokens.
     *
     * @param string[] $uids
     * @return array
     */
    protected function getUserNamesByUid(array $uids) : array
    {
        if (empty($uids)) {
            return [];
        }
        return $this->bypassDataAuthorization(function () use ($uids) : array {
            $userObject = MetaObjectFactory::createFromString($this->getWorkbench(), 'exface.Core.USER');
            $sheet = DataSheetFactory::createFromObject($userObject);
            $sheet->getColumns()->addFromAttribute($userObject->getUidAttribute());
            $sheet->getColumns()->addFromAttribute($userObject->getAttribute('FULL_NAME'));
            $sheet->getFilters()->addConditionFromString(
                $userObject->getUidAttributeAlias(),
                implode(',', array_values(array_unique($uids))),
                ComparatorDataType::IN
            );
            $sheet->dataRead();
            $uidColumn = $sheet->getColumns()->getByAttribute($userObject->getUidAttribute());
            $nameColumn = $sheet->getColumns()->getByAttribute($userObject->getAttribute('FULL_NAME'));
            $users = [];
            foreach ($sheet->getRows() as $row) {
                $uid = (string) ($row[$uidColumn->getName()] ?? '');
                $name = $row[$nameColumn->getName()] ?? null;
                if ($uid !== '' && $name !== null) {
                    $users[strtolower($uid)] = [
                        'uid' => $uid,
                        'fullname' => htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    ];
                }
            }
            return $users;
        });
    }

    /**
     * Reconciles server-parsed, currently allowed tokens with mention rows before the transaction commits.
     *
     * @param DataSheetInterface $comments
     * @param DataTransactionInterface $transaction
     * @return void
     */
    protected function synchronizeMentions(DataSheetInterface $comments, DataTransactionInterface $transaction) : void
    {
        $commentIdColumn = $comments->getColumns()->getByAttribute($this->getCommentIdAttribute());
        $contentColumn = $comments->getColumns()->getByAttribute($this->getCommentContentAttribute());
        if ($commentIdColumn === false || $contentColumn === false) {
            return;
        }
        $commentUids = array_values(array_unique(array_filter($commentIdColumn->getValues(false))));
        if (empty($commentUids)) {
            return;
        }
        $existingSheet = $this->readMentionRows($commentUids);
        $mentionCommentColumn = $existingSheet->getColumns()->getByAttribute($this->getMentionCommentUidAttribute());
        $mentionUserColumn = $existingSheet->getColumns()->getByAttribute($this->getMentionUserUidAttribute());
        $uidColumn = $existingSheet->getColumns()->getByAttribute($this->getMentionObject()->getUidAttribute());
        $existingByComment = [];
        foreach ($existingSheet->getRows() as $row) {
            $commentUid = (string) ($row[$mentionCommentColumn->getName()] ?? '');
            $userUid = (string) ($row[$mentionUserColumn->getName()] ?? '');
            $existingByComment[$commentUid][strtolower($userUid)] = (string) ($row[$uidColumn->getName()] ?? '');
        }

        $requestedByComment = [];
        $requestedUids = [];
        foreach ($comments->getRows() as $row) {
            $commentUid = (string) ($row[$commentIdColumn->getName()] ?? '');
            $content = (string) ($row[$contentColumn->getName()] ?? '');
            $uids = $this->extractMentionUids($content);
            $requestedByComment[$commentUid] = $uids;
            $requestedUids = array_merge($requestedUids, $uids);
        }
        $allowedUsers = $this->getAllowedMentionUsers(array_values(array_unique($requestedUids)));
        foreach (array_unique($requestedUids) as $requestedUid) {
            if (! isset($allowedUsers[strtolower($requestedUid)])) {
                throw new RuntimeException('Comment mentions may only target enabled users who have an allowed role.');
            }
        }
        $insertRows = [];
        $deleteUids = [];
        $newMentionsByComment = [];
        foreach ($requestedByComment as $commentUid => $uids) {
            $desired = [];
            foreach ($uids as $uid) {
                $normalizedUid = strtolower($uid);
                if (isset($allowedUsers[$normalizedUid])) {
                    $desired[$normalizedUid] = $allowedUsers[$normalizedUid]['uid'];
                }
            }
            $existing = $existingByComment[$commentUid] ?? [];
            foreach ($existing as $normalizedUid => $mentionUid) {
                if (! isset($desired[$normalizedUid]) && $mentionUid !== '') {
                    $deleteUids[] = $mentionUid;
                }
            }
            foreach ($desired as $normalizedUid => $uid) {
                if (! isset($existing[$normalizedUid])) {
                    $insertRows[] = [
                        $this->getMentionCommentUidAttribute()->getAliasWithRelationPath() => $commentUid,
                        $this->getMentionUserUidAttribute()->getAliasWithRelationPath() => $uid
                    ];
                    $newMentionsByComment[$commentUid][] = $uid;
                }
            }
        }
        if (! empty($deleteUids)) {
            $deleteSheet = DataSheetFactory::createFromObject($this->getMentionObject());
            $deleteSheet->getColumns()->addFromAttribute($this->getMentionObject()->getUidAttribute());
            $deleteSheet->getFilters()->addConditionFromString(
                $this->getMentionObject()->getUidAttributeAlias(),
                implode(',', array_unique($deleteUids)),
                ComparatorDataType::IN
            );
            $this->bypassDataAuthorization(function () use ($deleteSheet, $transaction) : void {
                $deleteSheet->dataRead();
                if (! $deleteSheet->isEmpty()) {
                    $deleteSheet->dataDelete($transaction);
                }
            });
        }
        if (! empty($insertRows)) {
            $insertSheet = DataSheetFactory::createFromObject($this->getMentionObject());
            $insertSheet->getColumns()->addFromAttribute($this->getMentionCommentUidAttribute());
            $insertSheet->getColumns()->addFromAttribute($this->getMentionUserUidAttribute());
            $insertSheet->addRows($insertRows);
            $this->bypassDataAuthorization(function () use ($insertSheet, $transaction) : void {
                $insertSheet->dataCreate(false, $transaction);
            });
        }
        if (! empty($newMentionsByComment)) {
            $this->queueMentionNotifications($comments, $commentIdColumn, $newMentionsByComment, $transaction);
        }
    }

    /**
     * Accepts only complete `@0x` plus 32-hex UID tokens written by the comments library.
     *
     * @param string $content
     * @return string[]
     */
    protected function extractMentionUids(string $content) : array
    {
        preg_match_all('/(?<![A-Za-z0-9_])@0x[A-Za-z0-9_-]*/i', $content, $candidates);
        $uids = [];
        foreach ($candidates[0] ?? [] as $candidate) {
            if (! preg_match('/^@(0x[0-9a-fA-F]{32})$/', $candidate, $match)) {
                throw new RuntimeException('A comment contains a malformed mention token.');
            }
            $uids[] = $match[1];
        }
        return array_values(array_unique($uids));
    }

    /**
     * Defers notifications until the workbench shutdown confirms the comment transaction committed.
     *
     * @param DataSheetInterface $comments
     * @param DataColumn $commentIdColumn
     * @param array $newMentionsByComment
     * @param DataTransactionInterface $transaction
     * @return void
     */
    protected function queueMentionNotifications(DataSheetInterface $comments, DataColumn $commentIdColumn, array $newMentionsByComment, DataTransactionInterface $transaction) : void
    {
        $authorAttribute = $this->getCommentAuthorIdAttribute();
        if ($authorAttribute === null) {
            throw new BehaviorConfigurationError($this, 'Configure `comment_author_id_attribute` to prevent sending a mention notification to the comment author.');
        }
        $authorColumn = $comments->getColumns()->getByAttribute($authorAttribute);
        $filterAttribute = $this->mentionNotificationFilterValueAttributeAlias === null
            ? null
            : $this->getObject()->getAttribute($this->mentionNotificationFilterValueAttributeAlias);
        $filterValueColumn = $filterAttribute === null ? null : $comments->getColumns()->getByAttribute($filterAttribute);
        $metadataByComment = [];
        if (($authorAttribute !== null && $authorColumn === false) || ($filterAttribute !== null && $filterValueColumn === false)) {
            $metadataSheet = DataSheetFactory::createFromObject($this->getObject());
            $metadataSheet->getColumns()->addFromAttribute($this->getCommentIdAttribute());
            if ($authorAttribute !== null) {
                $metadataSheet->getColumns()->addFromAttribute($authorAttribute);
            }
            if ($filterAttribute !== null) {
                $metadataSheet->getColumns()->addFromAttribute($filterAttribute);
            }
            $metadataSheet->getFilters()->addConditionFromString(
                $this->getCommentIdAttribute()->getAliasWithRelationPath(),
                implode(',', array_keys($newMentionsByComment)),
                ComparatorDataType::IN
            );
            $metadataSheet->dataRead();
            $metadataIdColumn = $metadataSheet->getColumns()->getByAttribute($this->getCommentIdAttribute());
            $metadataAuthorColumn = $authorAttribute === null ? null : $metadataSheet->getColumns()->getByAttribute($authorAttribute);
            $metadataFilterColumn = $filterAttribute === null ? null : $metadataSheet->getColumns()->getByAttribute($filterAttribute);
            foreach ($metadataSheet->getRows() as $metadataRow) {
                $commentUid = (string) ($metadataRow[$metadataIdColumn->getName()] ?? '');
                $metadataByComment[$commentUid] = [
                    'author_uid' => $metadataAuthorColumn === false ? null : ($metadataRow[$metadataAuthorColumn->getName()] ?? null),
                    'filter_value' => $metadataFilterColumn === false ? null : ($metadataRow[$metadataFilterColumn->getName()] ?? null)
                ];
            }
        }
        foreach ($comments->getRows() as $row) {
            $commentUid = (string) ($row[$commentIdColumn->getName()] ?? '');
            foreach ($newMentionsByComment[$commentUid] ?? [] as $userUid) {
                $authorUid = $authorColumn === false
                    ? ($metadataByComment[$commentUid]['author_uid'] ?? null)
                    : ($row[$authorColumn->getName()] ?? null);
                if ($authorUid === null || (string) $authorUid === '') {
                    throw new RuntimeException('Cannot send a mention notification without the saved comment author UID.');
                }
                if ($authorUid !== null && strcasecmp((string) $authorUid, $userUid) === 0) {
                    continue;
                }
                $notificationRow = $row;
                $notificationAttributes = [
                    $authorAttribute->getAliasWithRelationPath() => $authorUid
                ];
                if ($filterAttribute !== null) {
                    $notificationAttributes[$filterAttribute->getAliasWithRelationPath()] = $filterValueColumn === false
                        ? ($metadataByComment[$commentUid]['filter_value'] ?? null)
                        : ($row[$filterValueColumn->getName()] ?? null);
                }
                $this->pendingMentionNotifications[] = [
                    'transaction' => $transaction,
                    'user_uid' => $userUid,
                    'filter_value' => $filterAttribute === null
                        ? null
                        : ($notificationAttributes[$filterAttribute->getAliasWithRelationPath()] ?? null),
                    'comment_sheet' => $this->createSingleRowSheet($comments, $notificationRow, $notificationAttributes)
                ];
            }
        }
        if ($this->pendingMentionNotifications && ! $this->stopListenerRegistered) {
            $this->getWorkbench()->eventManager()->addListener(
                OnBeforeStopEvent::getEventName(),
                [$this, 'sendPendingMentionNotifications'],
                $this->getPriority()
            );
            $this->stopListenerRegistered = true;
        }
    }

    /**
     * Sends mention notifications only after the comment transaction committed successfully.
     *
     * @param OnBeforeStopEvent $event
     * @return void
     */
    public function sendPendingMentionNotifications(OnBeforeStopEvent $event) : void
    {
        if ($event->getWorkbench() !== $this->getWorkbench()) {
            return;
        }
        $pending = $this->pendingMentionNotifications;
        $this->pendingMentionNotifications = [];
        foreach ($pending as $notification) {
            if (! $notification['transaction']->isCommitted()) {
                continue;
            }
            $message = [
                'channel' => 'exface.Core.NOTIFICATION',
                'recipient_users' => [$notification['user_uid']],
                'title' => 'You were mentioned in a comment',
                'text' => 'You were mentioned in a comment.'
            ];
            if ($this->mentionNotificationPageAlias !== null
                && $this->mentionNotificationFilterAttribute !== null
                && $notification['filter_value'] !== null) {
                $message['buttons'] = [[
                    'caption' => 'Open',
                    'action' => [
                        'alias' => 'exface.Core.GoToUrl',
                        'url' => $this->buildMentionNotificationUrl((string) $notification['filter_value'])
                    ]
                ]];
            }
            $envelopes = $this->getMessageEnvelopes(
                new UxonObject([$message]),
                $notification['comment_sheet']
            );
            foreach ($envelopes as $envelope) {
                $this->getWorkbench()->getCommunicator()->send($envelope);
            }
        }
        $this->getWorkbench()->eventManager()->removeListener(
            OnBeforeStopEvent::getEventName(),
            [$this, 'sendPendingMentionNotifications']
        );
        $this->stopListenerRegistered = false;
    }

    /**
     * Builds a direct page URL in the same lowercase `.html` form as AbstractAjaxFacade navigation.
     *
     * @param string $filterValue
     * @return string
     */
    protected function buildMentionNotificationUrl(string $filterValue) : string
    {
        return mb_strtolower($this->mentionNotificationPageAlias)
            . '.html?'
            . http_build_query([
                'filter_' . $this->mentionNotificationFilterAttribute => $filterValue
            ]);
    }

    /**
     * Tells SendMessagesFromDataTrait to apply the same data-read authorization as NotifyingBehavior.
     *
     * @return bool
     */
    protected function willSendOnlyForAuthorizedData() : bool
    {
        return true;
    }

    /**
     * Copies a single comment row so authorization and notification placeholders use that exact saved record.
     *
     * @param DataSheetInterface $source
     * @param array $row
     * @param array $additionalAttributeValues
     * @return DataSheetInterface
     */
    protected function createSingleRowSheet(DataSheetInterface $source, array $row, array $additionalAttributeValues = []) : DataSheetInterface
    {
        $sheet = $source->copy();
        $sheet->removeRows();
        foreach ($additionalAttributeValues as $attributeAlias => $value) {
            $column = $sheet->getColumns()->addFromAttribute($this->getObject()->getAttribute($attributeAlias));
            $row[$column->getName()] = $value;
        }
        $sheet->addRow($row);
        return $sheet;
    }
    
    /**
     * 
     * @return MetaAttributeInterface
     */
    public function getCommentCreatedDateAttribute() : MetaAttributeInterface
    {
        return $this->getObject()->getAttribute($this->commentCreatedDateAttributeAlias);
    }
    
    /**
     * Alias of the attribute, that represents the date of creation
     * 
     * @uxon-property comment_created_date_attribute
     * @uxon-type metamodel:attribute
     * @uxon-required true
     * 
     * @param string $value
     * @return CommentBehavior
     */
    protected function setCommentCreatedDateAttribute(string $value) : CommentBehavior
    {
        $this->commentCreatedDateAttributeAlias = $value;
        return $this;
    }

    /**
     * 
     * @return MetaAttributeInterface
     */
    public function getCommentContentAttribute() : MetaAttributeInterface
    {
        return $this->getObject()->getAttribute($this->commentContentAttributeAlias);
    }
    
    /**
     * Alias of the attribute, that represents the comment as a text
     * 
     * @uxon-property comment_content_attribute
     * @uxon-type metamodel:attribute
     * @uxon-required true
     * 
     * @param string $value
     * @return CommentBehavior
     */
    protected function setCommentContentAttribute(string $value) : CommentBehavior
    {
        $this->commentContentAttributeAlias = $value;
        return $this;
    }
    
    /**
     * 
     * @return MetaAttributeInterface
     */
    public function getCommentEditedDateAttribute() : MetaAttributeInterface
    {
        return $this->getObject()->getAttribute($this->commentEditedDateAttributeAlias);
    }
    
    /**
     * Alias of the attribute, that represents the date of modification
     * 
     * @uxon-property comment_edited_date_attribute
     * @uxon-type metamodel:attribute
     * @uxon-required true
     * 
     * @param string $value
     * @return CommentBehavior
     */
    protected function setCommentEditedDateAttribute(string $value) : CommentBehavior
    {
        $this->commentEditedDateAttributeAlias = $value;
        return $this;
    }
    
    /**
     * 
     * @return MetaAttributeInterface
     */
    public function getCommentAuthorAttribute() : ?MetaAttributeInterface
    {
        return $this->getObject()->getAttribute($this->commentAuthorAttributeAlias);
    }
    
    /**
     * Alias of the attribute, that represents the Author Full Name
     *
     * @uxon-property comment_author_attribute
     * @uxon-type metamodel:attribute
     * @uxon-required true
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setCommentAuthorAttribute(string $value) : CommentBehavior
    {
        $this->commentAuthorAttributeAlias = $value;
        return $this;
    }
    
    /**
     * 
     * @return MetaAttributeInterface
     */
    public function getCommentIdAttribute() : ?MetaAttributeInterface
    {
        if ($this->commentIdAttributeAlias === null && $this->getObject()->hasUidAttribute()) {
            $this->commentIdAttributeAlias = $this->getObject()->getUidAttributeAlias();
        }
        return $this->getObject()->getAttribute($this->commentIdAttributeAlias);
    }
    
    /**
     * Alias of the attribute, that represents the UID of the comment
     *
     * @uxon-property comment_id_attribute
     * @uxon-type metamodel:attribute
     * @uxon-required true
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setCommentIdAttribute(string $value) : CommentBehavior
    {
        $this->commentIdAttributeAlias = $value;
        return $this;
    }
    
    /**
     * 
     * @return MetaAttributeInterface
     */
    public function getCommentAuthorIdAttribute() : ?MetaAttributeInterface
    {
        return $this->getObject()->getAttribute($this->commentAuthorIdAttributeAlias);
    }
    
    /**
     * Alias of the attribute, that represents the UID of the author
     *
     * @uxon-property comment_author_id_attribute
     * @uxon-type metamodel:attribute
     * @uxon-required true
     *
     * @param string $value
     * @return CommentBehavior
     */
    protected function setCommentAuthorIdAttribute(string $value) : CommentBehavior
    {
        $this->commentAuthorIdAttributeAlias = $value;
        return $this;
    } 
    
    /**
     * 
     * @return array<MetaAttributeInterface|null>
     */
    public function getFileAttributes() : array
    {
        $attrs = [];
        if (null !== $attr = $this->getCommentIdAttribute()) {
            $attrs[] = $attr;
        }
        if (null !== $attr = $this->getCommentCreatedDateAttribute()) {
            $attrs[] = $attr;
        }
        if (null !== $attr = $this->getCommentContentAttribute()) {
            $attrs[] = $attr;
        }
        if (null !== $attr = $this->getCommentEditedDateAttribute()) {
            $attrs[] = $attr;
        }
        if (null !== $attr = $this->getCommentAuthorIdAttribute()) {
            $attrs[] = $attr;
        }
        if (null !== $attr = $this->getCommentAuthorAttribute()) {
            $attrs[] = $attr;
        }
        return $attrs;
    }
}