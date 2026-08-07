<template>
    <div class="setting-automation-audit">
        <div class="filters">
            <Input v-model="filters.action" clearable :placeholder="$L('操作类型')" @on-enter="loadData(1)"/>
            <Input v-model="filters.result" clearable :placeholder="$L('执行结果')" @on-enter="loadData(1)"/>
            <Input v-model="filters.userid" clearable :placeholder="$L('用户ID')" @on-enter="loadData(1)"/>
            <Button type="primary" icon="md-search" @click="loadData(1)">{{$L('搜索')}}</Button>
        </div>
        <Table :loading="loading" :columns="columns" :data="rows" stripe/>
        <Page
            v-if="total > pageSize"
            class="pagination"
            :total="total"
            :current="page"
            :page-size="pageSize"
            show-total
            @on-change="loadData"/>
    </div>
</template>

<script>
export default {
    name: 'SettingAutomationAudit',
    data() {
        return {
            loading: false,
            rows: [],
            total: 0,
            page: 1,
            pageSize: 50,
            filters: {action: '', result: '', userid: 0},
        }
    },
    computed: {
        columns() {
            return [
                {title: this.$L('时间'), key: 'created_at', width: 168},
                {title: this.$L('用户ID'), key: 'userid', width: 90},
                {title: this.$L('令牌'), minWidth: 180, render: (h, {row}) => h('div', [
                    h('div', row.token ? row.token.name : '-'),
                    h('small', row.token ? row.token.access_key : `#${row.token_id || '-'}`),
                ])},
                {title: this.$L('操作类型'), key: 'action', minWidth: 150},
                {title: this.$L('资源'), minWidth: 120, render: (h, {row}) => h('span', row.resource_type ? `${row.resource_type} #${row.resource_id || '-'}` : '-')},
                {title: 'IP', key: 'ip', width: 130},
                {title: this.$L('客户端'), key: 'user_agent', minWidth: 180, tooltip: true},
                {title: this.$L('执行结果'), key: 'result', minWidth: 140},
            ]
        },
    },
    mounted() {
        this.loadData(1)
    },
    methods: {
        loadData(page = 1) {
            this.loading = true
            this.$store.dispatch('call', {
                url: 'token/admin/audits',
                data: {...this.filters, page, pagesize: this.pageSize},
            }).then(({data}) => {
                this.rows = data.data
                this.total = data.total
                this.page = data.current_page
            }).catch(({msg}) => $A.modalError(msg)).finally(() => this.loading = false)
        },
    },
}
</script>

<style lang="scss" scoped>
.setting-automation-audit {
    .filters { display: grid; grid-template-columns: 1fr 1fr 140px auto; gap: 10px; margin-bottom: 16px; }
    .pagination { margin-top: 16px; text-align: right; }
}
@media (max-width: 760px) {
    .setting-automation-audit .filters { grid-template-columns: 1fr; }
}
</style>
