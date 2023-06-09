<!--
  - Index.vue
  - Copyright (c) 2022 james@firefly-iii.org
  -
  - This file is part of Firefly III (https://github.com/firefly-iii).
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program.  If not, see <https://www.gnu.org/licenses/>.
  -->

<template>
  <q-page>
    <div class="row q-mx-md">
      <div class="col-xl-4 col-lg-6 col-md-12 q-pa-xs">
        <q-card bordered>
          <q-card-section>
            <div class="text-h6">Language and locale
              <span v-if="true === isOk.language" class="text-secondary"><span
                class="far fa-check-circle"></span></span>
              <span v-if="true === isLoading.language" class="text-blue"><span
                class="fas fa-spinner fa-spin"></span></span>
              <span v-if="true === isFailure.language" class="text-red"><span
                class="fas fa-skull-crossbones"></span> <small>Please refresh the page...</small></span>
            </div>
          </q-card-section>
          <q-card-section>

            <q-select
              v-model="language"
              :options="languages"
              bottom-slots emit-value
              label="I prefer the following language" map-options outlined/>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-xl-4 col-lg-6 col-md-12 q-pa-xs">
        <q-card bordered>
          <q-card-section>
            <div class="text-h6">Accounts on the home screen

              <span v-if="true === isOk.accounts" class="text-secondary"><span
                class="far fa-check-circle"></span></span>
              <span v-if="true === isLoading.accounts" class="text-blue"><span
                class="fas fa-spinner fa-spin"></span></span>
              <span v-if="true === isFailure.accounts" class="text-red"><span
                class="fas fa-skull-crossbones"></span> <small>Please refresh the page...</small></span>
            </div>
          </q-card-section>
          <q-card-section>
            <q-select
              v-model="accounts"
              :options="allAccounts"
              bottom-slots
              emit-value
              label="I want to see these accounts on the dashboard" map-options
              multiple outlined use-chips/>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-xl-4 col-lg-6 col-md-12 q-pa-xs">
        <q-card bordered>
          <q-card-section>
            <div class="text-h6">View range and list size

              <span v-if="true === isOk.pageSize" class="text-secondary"><span
                class="far fa-check-circle"></span></span>
              <span v-if="true === isLoading.pageSize" class="text-blue"><span
                class="fas fa-spinner fa-spin"></span></span>
              <span v-if="true === isFailure.pageSize" class="text-red"><span
                class="fas fa-skull-crossbones"></span> <small>Please refresh the page...</small></span>
            </div>
          </q-card-section>
          <q-card-section>
            <q-input v-model="pageSize" label="Page size" outlined step="1" type="number"/>
          </q-card-section>
          <q-card-section>
            <q-select
              v-model="viewRange"
              :options="viewRanges"
              bottom-slots
              emit-value
              label="Default period and view range" map-options outlined/>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-xl-4 col-lg-6 col-md-12 q-pa-xs">
        <q-card bordered>
          <q-card-section>
            <div class="text-h6">Optional transaction fields

              <span v-if="true === isOk.transactionFields" class="text-secondary"><span
                class="far fa-check-circle"></span></span>
              <span v-if="true === isLoading.transactionFields" class="text-blue"><span
                class="fas fa-spinner fa-spin"></span></span>
              <span v-if="true === isFailure.transactionFields" class="text-red"><span
                class="fas fa-skull-crossbones"></span> <small>Please refresh the page...</small></span>
            </div>
          </q-card-section>
          <q-tabs
            v-model="tab" dense
          >
            <q-tab label="Date fields" name="date"/>
            <q-tab label="Meta data fields" name="meta"/>
            <q-tab label="Reference fields" name="ref"/>
          </q-tabs>
          <q-tab-panels v-model="tab" animated swipeable>
            <q-tab-panel name="date">
              <q-option-group
                v-model="transactionFields.date"
                :options="allTransactionFields.date"
                type="checkbox"
              />
            </q-tab-panel>
            <q-tab-panel name="meta">
              <q-option-group
                v-model="transactionFields.meta"
                :options="allTransactionFields.meta"
                type="checkbox"
              />
            </q-tab-panel>
            <q-tab-panel name="ref">
              <q-option-group
                v-model="transactionFields.ref"
                :options="allTransactionFields.ref"
                type="checkbox"
              />
            </q-tab-panel>
          </q-tab-panels>
        </q-card>
      </div>
    </div>
  </q-page>
</template>

<script>
import Configuration from "../../api/system/configuration";
import Put from "../../api/preferences/put";
import Preferences from "../../api/preferences";
import List from "../../api/accounts/list";
import {useFireflyIIIStore} from "../../stores/fireflyiii";
// import {mapGetters} from "vuex";

export default {
  name: 'Index',
  mounted() {
    this.store = useFireflyIIIStore();
    this.isOk = {
      language: true,
      accounts: true,
      pageSize: true,
      transactionFields: true,
    };
    this.isLoading = {
      language: false,
      accounts: false,
      pageSize: false,
      transactionFields: false,
    };
    this.isFailure = {
      language: false,
      accounts: false,
      pageSize: false,
      transactionFields: false,
    };
    // get select lists for certain preferences
    this.getLanguages();
    this.getLanguage();
    this.getAssetAccounts().then(() => {
      this.getPreferredAccounts()
    });
    this.getViewRanges().then(() => {
      this.getPreferredViewRange()
    });
    this.getPageSize();
    this.getOptionalFields();
  },
  data() {
    return {
      // data for select lists
      languages: [],
      allAccounts: [],
      tab: 'date',
      allTransactionFields: {
        date: [
          {label: 'Interest date', value: 'interest_date'},
          {label: 'Book date', value: 'book_date'},
          {label: 'Processing date', value: 'process_date'},
          {label: 'Due date', value: 'due_date'},
          {label: 'Payment date', value: 'payment_date'},
          {label: 'Invoice date', value: 'invoice_date'},
        ],
        meta: [
          {label: 'Notes', value: 'notes'},
          {label: 'Location', value: 'location'},
          {label: 'Attachments', value: 'attachments'},
        ],
        ref: [
          {label: 'Internal reference', value: 'internal_reference'},
          {label: 'Transaction links', value: 'links'},
          {label: 'External URL', value: 'external_url'},
          {label: 'External ID', value: 'external_id'},
        ]
      },
      viewRanges: [],

      // is loading:
      isOk: {},
      isLoading: {},
      isFailure: {},

      // preferences:
      language: 'en_US',
      viewRange: '1M',
      pageSize: 50,
      accounts: [],
      transactionFields: {
        date: [],
        meta: [],
        ref: []
      },
      store: null,
    }
  },
  watch: {
    pageSize: function (value) {
      this.isOk.language = false;
      this.isLoading.language = true;
      (new Put).put('listPageSize', value).then(() => {
        this.store.refreshCacheKey();
        this.isOk.pageSize = true;
        this.isLoading.pageSize = false;
        this.isFailure.pageSize = false;
      }).catch(() => {
        this.isOk.pageSize = false;
        this.isLoading.pageSize = false;
        this.isFailure.pageSize = true;
      });
    },
    'transactionFields.date': function () {
      this.submitTransactionFields();
    },
    'transactionFields.meta': function () {
      this.submitTransactionFields();
    },
    'transactionFields.ref': function () {
      this.submitTransactionFields();
    },
    language: function (value) {
      this.isOk.language = false;
      this.isLoading.language = true;
      (new Put).put('language', value).then(() => {
        this.store.refreshCacheKey();
        this.isOk.language = true;
        this.isLoading.language = false;
        this.isFailure.language = false;
      }).catch(() => {
        this.isOk.language = false;
        this.isLoading.language = false;
        this.isFailure.language = true;
      });
    },
    accounts: function (value) {
      (new Put).put('frontpageAccounts', value).then(() => {
        this.store.refreshCacheKey();
        this.isOk.accounts = true;
        this.isLoading.accounts = false;
        this.isFailure.accounts = false;
      }).catch(() => {
        this.isOk.accounts = false;
        this.isLoading.accounts = false;
        this.isFailure.accounts = true;
      });
    },
    viewRange: function (value) {
      (new Put).put('viewRange', value).then(() => {
        this.store.refreshCacheKey();
        this.isOk.pageSize = true;
        this.isLoading.pageSize = false;
        this.isFailure.pageSize = false;
      }).catch(() => {
        this.isOk.pageSize = false;
        this.isLoading.pageSize = false;
        this.isFailure.pageSize = true;
      });
    },
  },
  computed: {
    // ...mapGetters('fireflyiii', ['getCacheKey']),
  },
  methods: {
    getAssetAccounts: function () {
      return this.getAssetAccountPage(1);
    },
    getAssetAccountPage: function (page) {
      return (new List).list('asset', page, this.getCacheKey).then((response) => {
        let totalPages = parseInt(response.data.meta.pagination.total_pages);

        // parse accounts:
        for (let i in response.data.data) {
          if (response.data.data.hasOwnProperty(i)) {
            let current = response.data.data[i];
            this.allAccounts.push({value: parseInt(current.id), label: current.attributes.name});
          }
        }
        if (totalPages > page) {
          this.getAssetAccountPage(page + 1);
        }
      });
    },
    submitTransactionFields: function () {
      let submission = {};
      for (let i in this.transactionFields) {
        if (this.transactionFields.hasOwnProperty(i)) {
          let set = this.transactionFields[i];
          for (let ii in set) {
            if (set.hasOwnProperty(ii)) {
              let value = set[ii];
              submission[value] = true;
            }
          }
        }
      }
      (new Put).put('transaction_journal_optional_fields', submission).then(() => {
        this.store.refreshCacheKey();
        this.isOk.transactionFields = true;
        this.isLoading.transactionFields = false;
        this.isFailure.transactionFields = false;
      }).catch(() => {
        this.isOk.transactionFields = false;
        this.isLoading.transactionFields = false;
        this.isFailure.transactionFields = true;
      });
    },
    getOptionalFields: function () {
      (new Preferences).getByName('transaction_journal_optional_fields').then((response) => {
        let preferences = response.data.data.attributes.data;
        for (let i in preferences) {
          // loop over allTransactionFields
          for (let ii in this.allTransactionFields) {
            if (this.allTransactionFields.hasOwnProperty(ii)) {
              let set = this.allTransactionFields[ii];
              for (let iii in set) {
                if (set.hasOwnProperty(iii)) {
                  let field = set[iii];
                  if (i === field.value && true === preferences[i]) {
                    this.transactionFields[ii].push(i);
                  }
                }
              }
            }
          }
        }
      })
    },
    getLanguage: function () {
      (new Preferences).getByName('language').then((response) => {
        this.language = response.data.data.attributes.data;
      })
    },
    getPageSize: function () {
      (new Preferences).getByName('listPageSize').then((response) => {
        this.pageSize = response.data.data.attributes.data;
      })
    },
    getPreferredAccounts: function () {
      (new Preferences).getByName('frontpageAccounts').then((response) => {
        this.accounts = response.data.data.attributes.data;
      })
    },
    getPreferredViewRange: function () {
      (new Preferences).getByName('viewRange').then((response) => {
        this.viewRange = response.data.data.attributes.data;
      })
    },
    getLanguages: function () {
      // get languages
      let config = new Configuration();
      config.get('firefly.languages').then((response) => {
        let obj = response.data.data.value;
        for (let key in obj) {
          if (obj.hasOwnProperty(key)) {
            let lang = obj[key];
            this.languages.push({value: key, label: lang.name_locale + ' (' + lang.name_english + ')'});
          }
        }
      });
    },
    getViewRanges: function () {
      // get languages
      let config = new Configuration();
      return config.get('firefly.valid_view_ranges').then((response) => {
        let obj = response.data.data.value;
        for (let key in obj) {
          if (obj.hasOwnProperty(key)) {
            let lang = obj[key];
            this.viewRanges.push({value: lang, label: this.$t('firefly.pref_' + lang)});
          }
        }
      });
    }
  }
}
</script>
