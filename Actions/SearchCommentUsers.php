<?php
namespace exface\Core\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Widgets\CommentsFeed;

/**
 * Searches mention candidates only in the CommentBehavior attached to the invoking feed.
 */
class SearchCommentUsers extends AbstractAction
{
    /**
     * Limits privileged user-name lookup to a page and CommentsFeed already authorized for this user.
     *
     * @param TaskInterface $task
     * @param DataTransactionInterface $transaction
     * @return ResultInterface
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        if (! $task->isTriggeredOnPage() || ! $task->isTriggeredByWidget()) {
            throw new RuntimeException('Comment user search must be triggered by a CommentsFeed widget on an authorized page.');
        }

        $widget = $task->getWidgetTriggeredBy();
        if (! $widget instanceof CommentsFeed || ! $widget->getEnableMentions()) {
            throw new RuntimeException('Comment user search is only available to an enabled CommentsFeed widget.');
        }

        $behavior = $widget->getCommentBehavior();
        $term = trim((string) $task->getParameter('term'));
        if (mb_strlen($term) < 2) {
            throw new RuntimeException('Comment user search requires at least two characters.');
        }
        $users = $behavior->searchMentionUsers($term);

        $userObject = MetaObjectFactory::createFromString($this->getWorkbench(), 'exface.Core.USER');
        $resultSheet = DataSheetFactory::createFromObject($userObject);
        $uidColumn = $userObject->getUidAttribute();
        $nameColumn = $userObject->getAttribute('FULL_NAME');
        $resultSheet->getColumns()->addFromAttribute($uidColumn);
        $resultSheet->getColumns()->addFromAttribute($nameColumn);
        foreach ($users as $user) {
            $resultSheet->addRow([
                $uidColumn->getAliasWithRelationPath() => $user['uid'],
                $nameColumn->getAliasWithRelationPath() => $user['fullname']
            ]);
        }

        return ResultFactory::createDataResult($task, $resultSheet);
    }
}
