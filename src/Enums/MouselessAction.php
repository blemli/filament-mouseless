<?php

namespace Blemli\FilamentMouseless\Enums;

/**
 * Every action id the package binds out of the box. Purely optional DX
 * sugar: anywhere an action id is accepted (->remap(), config) a plain
 * string works too — and must be used for dynamic ids the enum can't
 * know (`custom.*`, `nav.resource.*`).
 */
enum MouselessAction: string
{
    // Submit & clipboard
    case Submit = 'crud.submit';
    case Save = 'crud.save';
    case CreateAnother = 'crud.create-another';
    case SelectAll = 'list.select-all';
    case CopyMarkdown = 'record.copy-markdown';
    case Print = 'record.print';
    case CommandPalette = 'nav.command-palette';

    // Record actions
    case Create = 'crud.create';
    case Edit = 'crud.edit';
    case View = 'crud.view';
    case Delete = 'crud.delete';
    case ForceDelete = 'crud.force-delete';
    case Restore = 'crud.restore';
    case Duplicate = 'crud.duplicate';
    case Attach = 'crud.attach';
    case Detach = 'crud.detach';
    case Cancel = 'crud.cancel';
    case History = 'record.history';
    case Merge = 'record.merge';
    case Split = 'record.split';
    case Comment = 'record.comment';
    case Approve = 'record.approve';
    case Reject = 'record.reject';
    case Archive = 'record.archive';
    case Favorite = 'record.favorite';
    case Watch = 'record.watch';
    case Lock = 'record.lock';
    case Share = 'record.share';

    // Table controls
    case Filter = 'list.filter';
    case Group = 'list.group';
    case Sort = 'list.sort';
    case Columns = 'list.columns';
    case BulkAction = 'list.bulk-action';
    case Export = 'list.export';
    case Import = 'list.import';

    // List navigation
    case Search = 'list.search';
    case Refresh = 'list.refresh';
    case NextPage = 'list.next-page';
    case PrevPage = 'list.prev-page';
    case NextRow = 'list.next-row';
    case PrevRow = 'list.prev-row';
    case ToggleRow = 'list.toggle-row';
    case SelectNextRow = 'list.select-next-row';
    case SelectPrevRow = 'list.select-prev-row';

    // UI & chrome
    case Goto = 'nav.goto';
    case Dashboard = 'nav.dashboard';
    case Profile = 'nav.profile';
    case Logout = 'nav.logout';
    case Language = 'nav.language';
    case RecentlyViewed = 'nav.recently-viewed';
    case Notifications = 'nav.notifications';
    case Help = 'ui.help';
    case Close = 'ui.close';
    case NextTab = 'ui.next-tab';
    case PrevTab = 'ui.prev-tab';
}
