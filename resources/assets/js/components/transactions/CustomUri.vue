<!--
  - CustomString.vue
  - Copyright (c) 2019 james@firefly-iii.org
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
  <div class="form-group"
       v-bind:class="{ 'has-error': hasError()}"
  >
    <div class="col-sm-12 text-sm">
      {{ title }}
    </div>
    <div class="col-sm-12">
      <div class="input-group">
        <input ref="uri" :name="name" :placeholder="title" spellcheck="false"
               :title="title" :value="value"
               autocomplete="off"
               class="form-control" type="url"
               @input="handleInput">
        <span class="input-group-btn">
            <button
                class="btn btn-default"
                tabIndex="-1"
                type="button"
                v-on:click="clearField"><i class="fa fa-trash-o"></i></button>
        </span>
      </div>
      <ul v-for="error in this.error" class="list-unstyled">
        <li class="text-danger">{{ error }}</li>
      </ul>
    </div>
  </div>
</template>

<script>
export default {
  name: "CustomString",
  props: {
    title: String,
    name: String,
    value: String,
    error: Array
  },
  methods: {
    handleInput(e) {
      this.$emit('input', this.$refs.uri.value);
    },
    clearField: function () {
      this.name = '';
      this.$refs.uri.value = '';
      this.$emit('input', this.$refs.uri.value);
    },
    hasError: function () {
      return this.error.length > 0;
    }
  }
}
</script>
