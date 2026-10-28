<?php
namespace exface\Core\Facades\AbstractAjaxFacade\Elements;

/**
 * Shares jQuery Comments initialization with HTML-based facades so each adapter can use the same action callbacks.
 *
 * @method string getId()
 * @method string getAjaxUrl()
 * @method string escapeString(string $value, bool $forJs = true, bool $escapeQuotes = true)
 * @method \exface\Core\Interfaces\WorkbenchInterface getWorkbench()
 * @method string buildJsDataLoadFunctionBody(string $oParamsJs, string $successJs = 'success', string $errorJs = 'error')
 */
trait JqueryCommentsTrait
{
    /**
     * Builds the library configuration in one reusable place so facade adapters cannot diverge on CRUD behavior.
     *
     * @param string $createClickFunction
     * @param string $editClickFunction
     * @param string $deleteClickFunction
     * @return string
     */
    protected function buildJsJqueryCommentsInit(
        string $createClickFunction,
        string $editClickFunction,
        string $deleteClickFunction
    ) : string
    {
        $widget = $this->getWidget();
        $elementSelector = '$(' . $this->escapeString('#' . $this->getId(), true, false) . ')';
        $commentIdColumn = $this->escapeString($widget->getCommentIdColumn()->getDataColumnName(), true, false);
        $commentCreatedColumn = $this->escapeString($widget->getCommentCreatedDateColumn()->getDataColumnName(), true, false);
        $commentContentColumn = $this->escapeString($widget->getCommentContentColumn()->getDataColumnName(), true, false);
        $commentAuthorIdColumn = $this->escapeString($widget->getCommentAuthorIdColumn()->getDataColumnName(), true, false);
        $commentAuthorColumn = $this->escapeString($widget->getCommentAuthorColumn()->getDataColumnName(), true, false);
        $createdByCurrentUserColumn = $this->escapeString($widget->getIsCurrentUserAuthor()->getDataColumnName(), true, false);
        $commentEditedColumn = $this->escapeString($widget->getCommentEditedDateColumn()->getDataColumnName(), true, false);
        $dateFormatJs = $this->escapeString(
            $this->getWorkbench()->getCoreApp()->getTranslator()->translate('LOCALIZATION.DATE.DATETIME_FORMAT'),
            true,
            false
        );

        $systemColsJs = '';
        foreach ($widget->getColumns() as $column) {
            if ($column->isBoundToAttribute()
                && $column->getAttribute()->getRelationPath()->isEmpty()
                && $column->getAttribute()->isSystem()) {
                $columnName = $this->escapeString($column->getDataColumnName(), true, false);
                $systemColsJs .= "{$columnName}: data[{$columnName}],";
            }
        }

        $pageAlias = $this->escapeString($widget->getPage()->getAliasWithNamespace(), true, false);
        $createButtonId = $this->escapeString($widget->getButtonCreate()->getId(), true, false);
        $editButtonId = $this->escapeString($widget->getButtonEdit()->getId(), true, false);
        $deleteButtonId = $this->escapeString($widget->getButtonDelete()->getId(), true, false);
        $eventNamespace = preg_replace('/[^A-Za-z0-9_]/', '_', $this->getId());
        $eventName = $this->escapeString('actionperformed.comments_' . $eventNamespace, true, false);

        $currentUserId = $this->escapeString($this->getWorkbench()->getSecurity()->getAuthenticatedUser()->getUid(), true, false);

        return <<<JS

            $(function() {
                var commentActionCallbacks = {};
                commentActionCallbacks[{$createButtonId}] = function() {
                    {$elementSelector}[0].successCallback({$elementSelector}.data('_exfInput'));
                    {$elementSelector}.data('comments').fetchDataAndRender();
                };
                commentActionCallbacks[{$editButtonId}] = function() {
                    {$elementSelector}[0].successCallback({$elementSelector}.data('_exfInput'));
                    {$elementSelector}.data('comments').fetchDataAndRender();
                };
                commentActionCallbacks[{$deleteButtonId}] = function() {
                    {$elementSelector}[0].successCallback();
                };
                $(document).off({$eventName});
                $(document).on({$eventName}, function(event, params) {
                    if (!params || params.page_alias !== {$pageAlias}) {
                        return;
                    }
                    var callback = commentActionCallbacks[params.trigger_widget_id];
                    if (callback) {
                        callback();
                    }
                });
                {$elementSelector}.comments({
                    profilePictureURL: '',
                    currentUserId: {$currentUserId},
                    roundProfilePictures: true,
                    textareaRows: 1,
                    enableAttachments: false,
                    enableHashtags: false,
                    enablePinging: false,
                    enableUpvoting: false,
                    enableReplying: false,
                    enableEditing: true,
                    scrollContainer: $(window),
                    fieldMappings: {
                        id: {$commentIdColumn},
                        created: {$commentCreatedColumn},
                        modified: '_modified',
                        content: {$commentContentColumn},
                        creator: {$commentAuthorIdColumn},
                        fullname: {$commentAuthorColumn},
                        createdByCurrentUser: {$createdByCurrentUserColumn},
                        dbModified: {$commentEditedColumn}
                    },
                    timeFormatter: function(time) {
                        return exfTools.date.format(time, {$dateFormatJs})
                    },
                    getComments: function(success, error) {
                        var oParams = {};
                        {$this->buildJsDataLoadFunctionBody('oParams')};
                    },
                    postComment: function(data, success, error) {
                        var oDataRow = {
                            {$commentContentColumn}: data[{$commentContentColumn}]
                        };
                        {$elementSelector}.data('_exfInput', oDataRow);
                        {$elementSelector}[0].successCallback = success;
                        {$elementSelector}[0].errorCallback = error;
                        {$createClickFunction}();
                    },
                    putComment: function(data, success, error) {
                        var oDataRow = {
                            {$systemColsJs}
                            {$commentContentColumn}: data[{$commentContentColumn}]
                        };
                        {$elementSelector}.data('_exfInput', oDataRow);
                        {$elementSelector}[0].successCallback = success;
                        {$elementSelector}[0].errorCallback = error;
                        {$editClickFunction}();
                    },
                    deleteComment: function(data, success, error) {
                        {$elementSelector}.data('_exfInput', data);
                        {$elementSelector}[0].successCallback = success;
                        {$elementSelector}[0].errorCallback = error;
                        {$deleteClickFunction}();
                    },
                    upvoteComment: function(data, success) {
                        success(data);
                    },
                    validateAttachments: function(attachments, callback) {
                        callback(attachments);
                    }
                });
            });
JS;
    }

    /**
     * Builds library options for mention search without exposing the user object or role scope to the client.
     *
     * @return string
     */
    protected function buildJsJqueryCommentsMentionOptions() : string
    {
        $widget = $this->getWidget();
        if (! $widget->getEnableMentions()) {
            return <<<JS
                enablePinging: false,
                searchUsers: function(term, success) { success([]); },
JS;
        }
        $widget->getCommentBehavior();

        $pageAlias = $this->escapeString($widget->getPage()->getAliasWithNamespace(), true, false);
        $widgetId = $this->escapeString($widget->getId(), true, false);
        $elementSelector = '$(' . $this->escapeString('#' . $this->getId(), true, false) . ')';
        $ajaxUrl = $this->escapeString($this->getAjaxUrl(), true, false);
        return <<<JS
                enablePinging: true,
                searchUsers: function(term, success, error) {
                    var searchTerm = $.trim(term || '');
                    if (searchTerm.length < 2) {
                        success([]);
                        return;
                    }
                    $.ajax({
                        url: {$ajaxUrl},
                        method: 'POST',
                        data: {
                            action: 'exface.Core.SearchCommentUsers',
                            resource: {$pageAlias},
                            element: {$widgetId},
                            term: searchTerm
                        },
                        success: function(json) {
                            var users = (json.rows || []).map(function(row) {
                                return { id: row.UID, fullname: row.FULL_NAME };
                            });
                            success(users);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            if (error) { error(jqXHR, textStatus, errorThrown); }
                            {$elementSelector}.trigger('exfCommentsUserSearchError', [jqXHR]);
                        }
                    });
                },
JS;
    }

    /**
     * Decodes the HTML-safe read value before editing so persisted text is not double-escaped.
     *
     * @param string $valueJs
     * @return string
     */
    protected function buildJsJqueryCommentsDecodeEscapedContent(string $valueJs) : string
    {
        return "(function(value) { var decoder = document.createElement('textarea'); decoder.innerHTML = value || ''; return decoder.value; })({$valueJs})";
    }
}
