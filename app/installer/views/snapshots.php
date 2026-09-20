<?php declare(strict_types=1);
$view->script('snapshots', 'installer:app/bundle/snapshots.js', ['vue']); ?>

<div id="snapshots" v-cloak>

    <div class="uk-margin uk-flex uk-flex-between uk-flex-wrap">
        <div class="uk-flex uk-flex-middle uk-flex-wrap">
            <h2 class="uk-h3 uk-margin-remove">{{ 'Snapshots' | trans }}</h2>
        </div>
        <div>
            <button class="uk-button uk-button-default" :disabled="busy" @click="purgeExpired" v-confirm="{ title: 'Purge expired snapshots?', text: 'Every snapshot whose retention window has run out is destroyed. The packages they hold cannot be restored afterwards.' }">{{ 'Purge expired' | trans }}</button>
        </div>
    </div>

    <p class="uk-text-muted">
        {{ 'A snapshot is taken before a package is removed. It holds the package\'s files and the database as it stood, and it is what the removal can be undone from.' | trans }}
        {{ retentionText }}
    </p>

    <div class="uk-overflow-auto">
        <table class="uk-table uk-table-hover uk-table-middle">
            <thead>
                <tr>
                    <th>{{ 'Package' | trans }}</th>
                    <th class="pk-table-width-100 uk-text-center">{{ 'Version' | trans }}</th>
                    <th class="uk-text-nowrap">{{ 'Taken' | trans }}</th>
                    <th class="uk-text-right uk-text-nowrap">{{ 'Size' | trans }}</th>
                    <th class="uk-text-nowrap">{{ 'Expires' | trans }}</th>
                    <th class="uk-table-shrink uk-preserve-width">
                        <ul class="uk-iconnav uk-flex-nowrap uk-invisible">
                            <li v-for="i in [1, 2]"><span uk-icon="info"></span></li>
                        </ul>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr class="uk-visible-toggle" v-for="snapshot in snapshots">
                    <td class="pk-table-min-width-100">
                        <div class="uk-text-nowrap">{{ title(snapshot) }}</div>
                        <div class="uk-text-muted uk-text-nowrap">{{ snapshot.package }}</div>
                        <div class="uk-text-danger" v-if="!snapshot.complete">{{ 'Nothing can be restored from this snapshot: it was interrupted while it was taken, or while it was being removed. Purge it to reclaim the disk it holds.' | trans }}</div>
                    </td>
                    <td class="uk-text-center">{{ snapshot.version }}</td>
                    <td class="uk-text-nowrap">{{ taken(snapshot) }}</td>
                    <td class="uk-text-right uk-text-nowrap">{{ size(snapshot) }}</td>
                    <td class="uk-text-nowrap">{{ expires(snapshot) }}</td>
                    <td class="uk-preserve-width">
                        <ul class="uk-iconnav uk-flex-nowrap uk-invisible-hover">
                            <li v-if="snapshot.complete"><a uk-icon="history" :uk-tooltip="'Restore' | trans" @click="confirmRestore(snapshot)"></a></li>
                            <li><a uk-icon="trash" :uk-tooltip="'Purge' | trans" @click="purge(snapshot)" v-confirm="{ title: 'Purge this snapshot?', text: 'The package files and the database dump it holds are destroyed. This package cannot be restored afterwards.' }"></a></li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3 class="uk-h2 uk-text-muted uk-text-center" v-show="!snapshots.length">{{ 'No snapshot has been taken.' | trans }}</h3>

    <v-modal ref="restore" :options="modalOptions" :closed="restoreClosed">

        <div class="uk-modal-header">
            <h2 class="uk-h4">{{ 'Restore %title%?' | trans({ title: title(snapshot) }) }}</h2>
        </div>

        <div class="uk-modal-body">

            <template v-if="!restored">
                <p>{{ 'The files of %package% go back into this installation, and the database is put back the way it was when this snapshot was taken.' | trans({ package: snapshot.package }) }}</p>

                <div class="uk-alert uk-alert-danger">
                    {{ 'Everything in the database returns to %date%. Anything written since - pages, posts, comments, users, settings - is replaced by what was there then, and you may have to sign in again.' | trans({ date: taken(snapshot) }) }}
                </div>

                <p>{{ 'The snapshot itself is kept, so this can be run again.' | trans }}</p>

                <div class="uk-alert uk-flex uk-flex-middle uk-background-muted uk-margin-remove-bottom" v-show="busy">
                    <v-loader></v-loader>
                    <span class="uk-margin-small-left">{{ 'Restoring' | trans }}...</span>
                </div>
            </template>

            <div class="uk-alert uk-alert-success uk-margin-remove" v-else>
                {{ 'Restored. Reload the panel to work with the restored installation.' | trans }}
            </div>

        </div>

        <div class="uk-modal-footer uk-text-right">

            <template v-if="!restored">
                <a class="uk-button uk-button-text uk-margin-right" :class="{ 'uk-disabled': busy }" @click.prevent="closeRestore">{{ 'Cancel' | trans }}</a>
                <a class="uk-button uk-button-danger" :class="{ 'uk-disabled': busy }" @click.prevent="restore">{{ 'Restore' | trans }}</a>
            </template>

            <a class="uk-button uk-button-primary" @click.prevent="reload" v-else>{{ 'Reload' | trans }}</a>

        </div>

    </v-modal>

</div>
