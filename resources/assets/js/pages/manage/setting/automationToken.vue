<template>
    <div class="setting-automation-token">
        <div class="toolbar">
            <Button type="primary" icon="md-add" @click="openCreate">{{$L('创建令牌')}}</Button>
        </div>
        <Loading v-if="loading && tokens.length === 0"/>
        <div v-else-if="tokens.length === 0" class="empty">{{$L('暂无访问令牌')}}</div>
        <div v-else class="token-list">
            <div v-for="token in tokens" :key="token.id" class="token-item">
                <div class="token-main">
                    <div class="token-title">
                        <strong>{{token.name}}</strong>
                        <Tag :color="statusColor(token.status)">{{$L(statusName(token.status))}}</Tag>
                    </div>
                    <div class="token-ak">{{token.access_key}}</div>
                    <div class="token-meta">{{$L('授权项目')}}: {{token.projects.map(item => item.name).join('、')}}</div>
                    <div class="token-meta">{{$L('访问范围')}}: {{scopeText(token)}}</div>
                    <div class="token-meta">{{$L('过期时间')}}: {{token.expires_at}} · {{$L('最近使用')}}: {{token.last_used_at || $L('从未使用')}}</div>
                </div>
                <div class="token-actions">
                    <Button v-if="token.status === 'active'" icon="md-refresh" @click="rotateToken(token)">{{$L('轮换密钥')}}</Button>
                    <Button v-if="token.status === 'active'" @click="disableToken(token)">{{$L('禁用')}}</Button>
                    <Button type="error" ghost @click="deleteToken(token)">{{$L('删除')}}</Button>
                </div>
            </div>
        </div>

        <Modal v-model="createVisible" :title="$L('创建访问令牌')" :mask-closable="false">
            <Form :label-width="90">
                <FormItem :label="$L('令牌名称')">
                    <Input v-model="form.name" :maxlength="100" :placeholder="$L('例如 codex-local')"/>
                </FormItem>
                <FormItem :label="$L('授权项目')">
                    <Select v-model="form.project_ids" multiple filterable>
                        <Option v-for="project in projects" :key="project.id" :value="project.id">{{project.name}}</Option>
                    </Select>
                </FormItem>
                <FormItem :label="$L('权限范围')">
                    <CheckboxGroup v-model="form.scopes">
                        <Checkbox label="file_cabinet">{{$L('文件柜')}}</Checkbox>
                    </CheckboxGroup>
                    <div class="scope-tip">{{$L('不勾选文件柜时，令牌不能调用文件柜 API。')}}</div>
                </FormItem>
                <Alert type="warning" show-icon>{{$L('令牌仅能访问所选项目，并沿用当前用户在项目和文件柜中的权限。')}}</Alert>
                <FormItem :label="$L('有效期')">
                    <Select v-model="form.days">
                        <Option :value="7">7 {{$L('天')}}</Option>
                        <Option :value="30">30 {{$L('天')}}</Option>
                        <Option :value="90">90 {{$L('天')}}</Option>
                    </Select>
                </FormItem>
            </Form>
            <div slot="footer">
                <Button @click="createVisible = false">{{$L('取消')}}</Button>
                <Button type="primary" :loading="submitting" @click="createToken">{{$L('创建')}}</Button>
            </div>
        </Modal>

        <Modal v-model="secretVisible" :title="$L('请立即保存密钥')" :mask-closable="false" :closable="false">
            <Alert type="warning" show-icon>{{$L('Secret Key 仅展示一次，关闭后无法再次查看。')}}</Alert>
            <div class="secret-row"><span>Access Key</span><Input :value="created.access_key" readonly/></div>
            <div class="secret-row"><span>Secret Key</span><Input :value="created.secret_key" readonly/></div>
            <div slot="footer">
                <Button type="primary" @click="closeSecret">{{$L('我已保存')}}</Button>
            </div>
        </Modal>
    </div>
</template>

<script>
export default {
    name: 'SettingAutomationToken',
    data() {
        return {
            loading: false,
            submitting: false,
            createVisible: false,
            secretVisible: false,
            tokens: [],
            projects: [],
            created: {},
            form: this.defaultForm(),
        }
    },
    computed: {
    },
    mounted() {
        this.loadData()
    },
    methods: {
        defaultForm() {
            return {name: '', project_ids: [], scopes: [], days: 30}
        },
        loadData() {
            this.loading = true
            Promise.all([
                this.$store.dispatch('call', {url: 'token/lists'}),
                this.$store.dispatch('call', {url: 'project/lists', data: {getstatistics: 'no'}}),
            ]).then(([tokenRes, projectRes]) => {
                this.tokens = tokenRes.data.list
                this.projects = projectRes.data.data
            }).catch(({msg}) => $A.modalError(msg)).finally(() => this.loading = false)
        },
        openCreate() {
            this.form = this.defaultForm()
            this.createVisible = true
        },
        createToken() {
            if (!this.form.name || !this.form.project_ids.length) {
                $A.messageWarning('请填写名称并选择项目')
                return
            }
            const expires = new Date(Date.now() + this.form.days * 86400000).toISOString()
            this.submitting = true
            this.$store.dispatch('call', {
                url: 'token/create',
                method: 'post',
                data: {...this.form, expires_at: expires},
            }).then(({data}) => {
                this.created = data
                this.createVisible = false
                this.secretVisible = true
                this.loadData()
            }).catch(({msg}) => $A.modalError(msg)).finally(() => this.submitting = false)
        },
        disableToken(token) {
            this.confirmAction(token, '禁用令牌', '禁用后使用该令牌的自动化工具将立即无法访问。', 'token/disable')
        },
        rotateToken(token) {
            $A.modalConfirm({
                title: '轮换密钥',
                content: '轮换后旧 Secret Key 将立即失效，是否继续？',
                loading: true,
                onOk: () => this.$store.dispatch('call', {
                    url: 'token/rotate',
                    method: 'post',
                    data: {id: token.id},
                }).then(({data, msg}) => {
                    this.created = data
                    this.secretVisible = true
                    this.loadData()
                    return msg
                }),
            })
        },
        deleteToken(token) {
            this.confirmAction(token, '删除令牌', '删除后无法恢复，使用该令牌的自动化工具将立即无法访问。', 'token/delete')
        },
        confirmAction(token, title, content, url) {
            $A.modalConfirm({title, content, loading: true, onOk: () => this.$store.dispatch('call', {url, method: 'post', data: {id: token.id}}).then(({msg}) => {
                this.loadData()
                return msg
            })})
        },
        closeSecret() {
            this.secretVisible = false
            this.created = {}
        },
        statusName(status) {
            return {active: '有效', disabled: '已禁用', expired: '已过期'}[status] || status
        },
        statusColor(status) {
            return {active: 'success', disabled: 'default', expired: 'error'}[status] || 'default'
        },
        scopeText(token) {
            const labels = [this.$L('项目任务')]
            if ((token.scopes || []).includes('file_cabinet')) {
                labels.push(this.$L('文件柜'))
            }
            return labels.join('、')
        },
    },
}
</script>

<style lang="scss" scoped>
.setting-automation-token {
    .toolbar { display: flex; justify-content: flex-end; margin-bottom: 16px; }
    .empty { padding: 48px 0; color: #999; text-align: center; }
    .token-list { border-top: 1px solid #eee; }
    .token-item { display: flex; gap: 16px; align-items: center; padding: 18px 0; border-bottom: 1px solid #eee; }
    .token-main { min-width: 0; flex: 1; }
    .token-title { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
    .token-ak, .token-meta { overflow-wrap: anywhere; color: #777; line-height: 22px; }
    .token-ak { color: #333; font-family: monospace; }
    .token-actions { display: flex; gap: 8px; }
    .scope-tip { margin-top: 4px; color: #999; line-height: 20px; }
    .secret-row { margin-top: 16px; }
    .secret-row > span { display: block; margin-bottom: 6px; font-weight: 600; }
}
@media (max-width: 640px) {
    .setting-automation-token {
        .token-item { align-items: stretch; flex-direction: column; }
        .token-actions { justify-content: flex-end; }
    }
}
</style>
