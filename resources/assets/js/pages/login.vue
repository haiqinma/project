<template>
    <div class="page-login">
        <PageTitle :title="$L('登录')"/>
        <div class="login-body">
            <div class="login-logo no-dark-content"></div>
            <div class="login-box">
                <div class="login-mode-switch">
                    <div class="login-mode-switch-box">
                        <ETooltip :disabled="$isEEUIApp || windowTouch" :content="$L(loginMode=='qrcode' ? '帐号登录' : '扫码登录')" placement="left">
                            <span class="login-mode-switch-icon no-dark-content" @click="switchLoginMode">
                                <svg v-if="loginMode=='qrcode'" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" data-icon="PcOutlined"><path d="M23 16a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h18a2 2 0 0 1 2 2v12ZM21 4H3v9h18V4ZM3 15v1h18v-1H3Zm3 6a1 1 0 0 1 1-1h10a1 1 0 1 1 0 2H7a1 1 0 0 1-1-1Z" fill="currentColor"></path></svg>
                                <svg v-else viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" data-icon="QrOutlined"><path d="M6.5 7.5a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1v-1Z" fill="currentColor"></path><path d="M4.5 2.5c-1.1 0-2 .9-2 2v7c0 1.1.9 2 2 2h7c1.1 0 2-.9 2-2v-7c0-1.1-.9-2-2-2h-7Zm0 2h7v7h-7v-7ZM11 16a1 1 0 1 1 2 0 1 1 0 0 1-2 0Zm0 3.5a1 1 0 1 1 2 0v1a1 1 0 1 1-2 0v-1Zm4-7.5a1 1 0 1 1 2 0 1 1 0 0 1-2 0Zm3.5 0a1 1 0 0 1 1-1h1a1 1 0 1 1 0 2h-1a1 1 0 0 1-1-1ZM15 17c0-1.1.9-2 2-2h2.5c1.1 0 2 .9 2 2v2.5c0 1.1-.9 2-2 2H17c-1.1 0-2-.9-2-2V17Zm4.5 0H17v2.5h2.5V17Zm-15-2c-1.1 0-2 .9-2 2v2.5c0 1.1.9 2 2 2H7c1.1 0 2-.9 2-2V17c0-1.1-.9-2-2-2H4.5Zm0 2H7v2.5H4.5V17ZM15 4.5c0-1.1.9-2 2-2h2.5c1.1 0 2 .9 2 2V7c0 1.1-.9 2-2 2H17c-1.1 0-2-.9-2-2V4.5Zm4.5 0H17V7h2.5V4.5Z" fill="currentColor"></path></svg>
                            </span>
                        </ETooltip>
                    </div>
                </div>

                <div class="login-title">{{welcomeTitle}}</div>

                <div class="login-subtitle">{{subTitle}}</div>

                <transition name="login-mode">
                    <div v-if="loginMode=='qrcode'" class="login-qrcode">
                        <div class="login-qrcode-frame" @click="qrcodeRefresh">
                            <VueQrcode v-if="qrcodeUrl" :value="qrcodeUrl" :options="{width:200,margin:2}"></VueQrcode>
                            <Loading v-else/>
                        </div>
                        <div v-if="qrcodeStatusText" class="login-qrcode-status">{{qrcodeStatusText}}</div>
                        <div
                            v-if="qrcodeMode === 'passport' && qrcodeUrlValue"
                            class="login-passport-local">
                            <Button type="text" @click="openPassportAuthorize">{{$L('无法扫码？使用本机通行证登录')}}</Button>
                        </div>
                    </div>
                </transition>
                <transition name="login-mode">
                    <div
                        v-if="loginMode=='access'"
                        class="login-access">
                        <Input
                            v-if="$isSoftware && cacheServerUrl"
                            :value="$A.getDomain(cacheServerUrl)"
                            prefix="ios-globe-outline"
                            size="large"
                            readonly
                            clearable
                            @on-clear="setServerUrl('')"/>

                        <Button v-if="loginType=='login'" type="primary" class="wallet-login-button" :loading="walletLoading" size="large" long @click="onWalletLogin">{{$L('钱包登录')}}</Button>

                        <Button v-if="loginType=='login'" type="text" class="email-login-toggle" long @click="emailLoginExpanded=!emailLoginExpanded">
                            {{$L('邮箱密码登录')}}
                            <Icon :type="emailLoginExpanded ? 'ios-arrow-up' : 'ios-arrow-down'"/>
                        </Button>

                        <transition name="login-expand">
                            <div v-if="emailLoginExpanded || loginType=='reg'" class="email-login-panel">
                                <Input
                                    v-model="email"
                                    ref="email"
                                    prefix="ios-mail-outline"
                                    :placeholder="$L('输入您的电子邮件')"
                                    type="email"
                                    size="large"
                                    @on-keydown="onLoginKeydown"
                                    @on-blur="onBlur"
                                    clearable/>

                                <Input
                                    v-model="password"
                                    ref="password"
                                    prefix="ios-lock-outline"
                                    :placeholder="$L('输入您的密码')"
                                    type="password"
                                    size="large"
                                    @on-keydown="onLoginKeydown"
                                    clearable/>

                                <Input
                                    v-if="loginType=='reg'"
                                    v-model="password2"
                                    ref="password2"
                                    prefix="ios-lock-outline"
                                    :placeholder="$L('输入确认密码')"
                                    type="password"
                                    size="large"
                                    @on-keydown="onLoginKeydown"
                                    clearable/>
                                <Input
                                    v-if="loginType=='reg' && needInvite"
                                    v-model="invite"
                                    ref="invite"
                                    class="login-code"
                                    :placeholder="$L('请输入注册邀请码')"
                                    type="text"
                                    size="large"
                                    @on-keydown="onLoginKeydown"
                                    clearable><span slot="prepend">&nbsp;{{$L('邀请码')}}&nbsp;</span></Input>

                                <Input
                                    v-if="loginType=='login' && codeNeed"
                                    v-model="code"
                                    ref="code"
                                    class="login-code"
                                    :placeholder="$L('输入图形验证码')"
                                    type="text"
                                    size="large"
                                    @on-keydown="onLoginKeydown"
                                    clearable>
                                    <Icon type="ios-checkmark-circle-outline" class="login-icon" slot="prepend"></Icon>
                                    <div slot="append" class="login-code-end" @click="refreshCode">
                                        <div v-if="codeLoad > 0" class="code-load"><Loading/></div>
                                        <span v-else-if="codeUrl === 'error'" class="code-error">{{$L('加载失败')}}</span>
                                        <img v-else :src="codeUrl"/>
                                    </div>
                                </Input>

                                <Button :type="loginType=='reg' ? 'primary' : 'default'" :loading="loadIng > 0 || loginJump" size="large" long @click="onLogin">{{loginText}}</Button>
                            </div>
                        </transition>

                        <div v-if="loginType=='reg'" class="login-switch">{{$L('已经有帐号？')}} <a href="javascript:void(0)" @click="loginType='login'">{{$L('登录帐号')}}</a></div>
                        <div v-else class="login-switch">{{$L('还没有帐号？')}} <a href="javascript:void(0)" @click="loginType='reg'">{{$L('注册帐号')}}</a></div>
                    </div>
                </transition>
            </div>
            <div class="login-bottom">
                <Dropdown trigger="click" placement="bottom-start">
                    <div class="login-setting">
                        {{$L('设置')}}
                        <i class="taskfont">&#xe689;</i>
                    </div>
                    <DropdownMenu slot="list" class="login-setting-menu">
                        <Dropdown placement="right-start" transfer @on-click="setTheme">
                            <DropdownItem>
                                <div class="login-setting-item">
                                    {{$L('主题皮肤')}}
                                    <Icon type="ios-arrow-forward"></Icon>
                                </div>
                            </DropdownItem>
                            <DropdownMenu slot="list">
                                <DropdownItem
                                    v-for="(item, key) in themeList"
                                    :key="key"
                                    :name="item.value"
                                    :selected="themeConf === item.value">{{$L(item.name)}}</DropdownItem>
                            </DropdownMenu>
                        </Dropdown>
                        <Dropdown placement="right-start" transfer @on-click="onLanguage">
                            <DropdownItem divided>
                                <div class="login-setting-item">
                                    {{currentLanguage}}
                                    <Icon type="ios-arrow-forward"></Icon>
                                </div>
                            </DropdownItem>
                            <DropdownMenu slot="list">
                                <DropdownItem
                                    v-for="(item, key) in languageList"
                                    :key="key"
                                    :name="key"
                                    :selected="languageName === key">{{item}}</DropdownItem>
                            </DropdownMenu>
                        </Dropdown>
                    </DropdownMenu>
                </Dropdown>
                <div class="login-forgot">{{$L('忘记密码了？')}} <a href="javascript:void(0)" @click="forgotPassword">{{$L('重置密码')}}</a></div>
            </div>
        </div>

        <!--隐私政策提醒-->
        <Modal
            v-model="privacyShow"
            :title="$L('隐私协议')"
            :mask-closable="false">
            <div class="privacy-content">
                <div>{{$L('欢迎使用本软件！')}}</div>
                <p>{{$L('在您使用本软件前，请您认真阅读并了解相应的')}}<a target="_blank" :href="$A.apiUrl('privacy')">《{{ $L('隐私政策') }}》</a>, {{$L('以了解我们的服务内容和您相关个人信息的处理规则。')}}{{$L('我们将严格的按照隐私服务协议为您提供服务，保护您的个人信息。')}}</p>
            </div>
            <div slot="footer" class="adaption">
                <Button type="default" @click="onPrivacy(false)">{{$L('不同意')}}</Button>
                <Button type="primary" @click="onPrivacy(true)">{{$L('同意')}}</Button>
            </div>
        </Modal>
    </div>
</template>

<script>
import {mapState} from "vuex";
import {languageList, languageName, setLanguage} from "../language";
import VueQrcode from "@chenfengyuan/vue-qrcode";
import emitter from "../store/events";
import {connectAndGetWalletProfile, getProvider, requestAccounts, signMessage} from "@yeying-community/web3-bs";

export default {
    components: {VueQrcode},
    data() {
        return {
            loadIng: 0,

            languageList,
            languageName,

            qrcodeVal: '',
            qrcodeUrlValue: '',
            qrcodeSessionId: '',
            qrcodeMode: 'legacy',
            qrcodeStatusText: '',
            qrcodeTimer: null,
            qrcodeBroadcastChannel: null,
            qrcodeLoad: false,
            qrcodeRefreshLoading: false,
            qrcodeRequestSeq: 0,

            codeNeed: false,
            codeLoad: 0,
            codeKey: '',
            codeUrl: '',

            loginMode: 'access',
            loginType: 'login',
            loginJump: false,
            walletLoading: false,
            emailLoginExpanded: false,

            email: '',
            password: '',
            password2: '',
            code: '',
            invite: '',

            needInvite: false,

            privacyShow: false,
        }
    },

    async mounted() {
        this.privacyShow = !!this.$isEEUIApp && (await $A.IDBString("cachePrivacyShow")) !== "no";
        this.email = await $A.IDBString("cacheLoginEmail") || ''
        //
        if (this.$isSoftware) {
            this.chackServerUrl().catch(_ => {});
        } else {
            this.setServerUrl('').catch(_ => {});
        }
        //
        this.qrcodeTimer = setInterval(this.qrcodeStatus, 2000);
        this.bindPassportCallbackEvents();
        //
        emitter.on('useSSOLogin', this.inputServerUrl);
    },

    beforeDestroy() {
        clearInterval(this.qrcodeTimer);
        this.unbindPassportCallbackEvents();
        emitter.off('useSSOLogin', this.inputServerUrl);
    },

    activated() {
        this.loginType = this.$route.query.type === 'reg' ? 'reg' : 'login'
        //
        this.getDemoAccount();
    },

    deactivated() {
        this.loginJump = false;
        this.password = "";
        this.password2 = "";
        this.code = "";
        this.invite = "";
    },

    computed: {
        ...mapState([
            'cacheServerUrl',

            'themeConf',
            'themeList',
        ]),

        currentLanguage() {
            return languageList[languageName] || 'Language'
        },

        welcomeTitle() {
            return this.loginMode == 'qrcode' ? this.$L('通行证扫码登录') : this.$L('夜莺项目管理')
        },

        subTitle() {
            if (this.loginMode == 'qrcode') {
                return this.$L('请使用手机相机或夜莺钱包扫码确认登录。')
            }
            if (this.loginType=='reg') {
                return this.$L('输入您的信息以创建帐户。')
            }
            return this.$L('输入您的凭证以访问您的帐户。')
        },

        loginText() {
            if (this.loginJump) {
                return this.loginType == 'login' ? this.$L('登录成功...') : this.$L('注册成功')
            }
            return this.loginType == 'login' ? this.$L('登录') : this.$L('注册')
        },

        qrcodeUrl() {
            if (this.qrcodeUrlValue) {
                return this.qrcodeUrlValue
            }
            return this.qrcodeVal ? $A.mainUrl('login?qrcode=' + this.qrcodeVal) : ''
        },
    },

    watch: {
        '$route' ({query}) {
            if (query.type=='reg'){
                this.$nextTick(()=>{
                    this.loginType = "reg"
                })
            }
        },
        loginMode() {
            if (this.loginMode !== 'qrcode') {
                this.qrcodeRequestSeq += 1;
                this.qrcodeRefreshLoading = false;
                return;
            }
            this.qrcodeRefresh()
        },
        loginType(val) {
            if (val == 'reg') {
                this.emailLoginExpanded = true;
                this.getNeedInvite();
            } else {
                this.emailLoginExpanded = false;
            }
        },
    },

    methods: {
        setTheme(mode) {
            this.$store.dispatch("setTheme", mode)
        },

        getDemoAccount() {
            if (this.isNotServer()) {
                return;
            }
            this.$store.dispatch("call", {
                url: 'system/demo',
            }).then(({data}) => {
                if (data.account) {
                    this.email = data.account;
                    this.password = data.password;
                }
            }).catch(_ => {
                //
            });
        },

        getNeedInvite() {
            this.$store.dispatch("call", {
                url: 'users/reg/needinvite',
            }).then(({data}) => {
                this.needInvite = !!data.need;
            }).catch(_ => {
                this.needInvite = false;
            });
        },

        switchLoginMode() {
            this.chackServerUrl(true).then(() => {
                if (this.loginMode === 'qrcode') {
                    this.loginMode = 'access'
                } else {
                    this.loginMode = 'qrcode'
                }
            })
        },

        qrcodeRefresh() {
            if (this.loginMode != 'qrcode' || this.qrcodeRefreshLoading) {
                return;
            }
            this.qrcodeRequestSeq += 1;
            const requestSeq = this.qrcodeRequestSeq;
            this.qrcodeRefreshLoading = true;
            this.qrcodeStatusText = '';
            this.$store.dispatch("call", {
                url: 'passport/login/session',
                data: {},
            }).then(({data}) => {
                if (requestSeq !== this.qrcodeRequestSeq || this.loginMode !== 'qrcode') {
                    return;
                }
                this.qrcodeMode = 'passport';
                this.qrcodeSessionId = data.session_id || '';
                this.qrcodeUrlValue = data.qrcode_url || '';
                this.qrcodeStatusText = '';
                if (!this.qrcodeSessionId || !this.qrcodeUrlValue) {
                    this.useLegacyQrcode();
                }
            }).catch(({data}) => {
                if (requestSeq !== this.qrcodeRequestSeq || this.loginMode !== 'qrcode') {
                    return;
                }
                this.useLegacyQrcode();
            }).finally(() => {
                if (requestSeq === this.qrcodeRequestSeq) {
                    this.qrcodeRefreshLoading = false;
                }
            });
        },

        useLegacyQrcode() {
            this.qrcodeMode = 'legacy';
            this.qrcodeSessionId = '';
            this.qrcodeUrlValue = '';
            this.qrcodeVal = $A.randomString(32);
            this.qrcodeStatusText = '';
        },

        qrcodeStatus() {
            if (this.routeName !== 'login' || this.loginMode != 'qrcode') {
                return;
            }
            if (this.qrcodeLoad || this.qrcodeRefreshLoading || (!this.qrcodeVal && !this.qrcodeSessionId)) {
                return;
            }
            const requestSeq = this.qrcodeRequestSeq;
            const requestMode = this.qrcodeMode;
            const requestSessionId = this.qrcodeSessionId;
            const requestQrcodeVal = this.qrcodeVal;
            this.qrcodeLoad = true
            const request = requestMode === 'passport'
                ? {url: 'passport/login/status', data: {session_id: requestSessionId}}
                : {url: 'users/login/qrcode?code=' + requestQrcodeVal};
            this.$store.dispatch("call", request).then(({data}) => {
                if (requestSeq !== this.qrcodeRequestSeq || requestMode !== this.qrcodeMode) {
                    return;
                }
                if (requestMode === 'passport' && requestSessionId !== this.qrcodeSessionId) {
                    return;
                }
                if (requestMode === 'legacy' && requestQrcodeVal !== this.qrcodeVal) {
                    return;
                }
                if (requestMode === 'passport') {
                    this.handlePassportQrcodeStatus(data);
                    return;
                }
                this.$store.dispatch("handleClearCache", data).then(this.goNext);
            }).catch(({data, msg}) => {
                if (requestSeq !== this.qrcodeRequestSeq || requestMode !== this.qrcodeMode) {
                    return;
                }
                if (requestMode === 'passport' && requestSessionId !== this.qrcodeSessionId) {
                    return;
                }
                if (requestMode === 'passport') {
                    if (data?.code === 'expired') {
                        this.qrcodeRefresh();
                        return;
                    }
                    if (['passport_wallet_unbound', 'wallet_email_required'].includes(data?.code)) {
                        this.qrcodeStatusText = msg || this.$L('扫码登录失败，请刷新二维码。');
                        return;
                    }
                    this.qrcodeStatusText = msg || this.$L('通行证登录状态异常，请刷新二维码。');
                }
            }).finally(_ => {
                this.qrcodeLoad = false
            });
        },

        handlePassportQrcodeStatus(data) {
            const status = data?.status || 'pending';
            if (status === 'approved' && data?.token) {
                this.$store.dispatch("handleClearCache", data).then(this.goNext);
                return;
            }
            if (status === 'scanned') {
                this.qrcodeStatusText = '';
                return;
            }
            if (status === 'approved') {
                this.qrcodeStatusText = '';
                return;
            }
            if (status === 'rejected') {
                this.qrcodeSessionId = '';
                this.qrcodeStatusText = this.$L('已拒绝登录，请刷新二维码。');
                return;
            }
            if (status === 'expired') {
                this.qrcodeRefresh();
                return;
            }
            if (status && status !== 'pending') {
                this.qrcodeStatusText = this.$L('通行证登录状态异常，请刷新二维码。');
                return;
            }
            this.qrcodeStatusText = '';
        },

        bindPassportCallbackEvents() {
            window.addEventListener('storage', this.onPassportCallbackStorage);
            window.addEventListener('focus', this.onPassportWindowFocus);
            document.addEventListener('visibilitychange', this.onPassportVisibilityChange);
            if ('BroadcastChannel' in window) {
                this.qrcodeBroadcastChannel = new BroadcastChannel('project-passport-login');
                this.qrcodeBroadcastChannel.onmessage = event => this.handlePassportCallbackEvent(event.data);
            }
        },

        unbindPassportCallbackEvents() {
            window.removeEventListener('storage', this.onPassportCallbackStorage);
            window.removeEventListener('focus', this.onPassportWindowFocus);
            document.removeEventListener('visibilitychange', this.onPassportVisibilityChange);
            if (this.qrcodeBroadcastChannel) {
                this.qrcodeBroadcastChannel.close();
                this.qrcodeBroadcastChannel = null;
            }
        },

        onPassportCallbackStorage(event) {
            if (event.key !== '__project_passport_callback__' || !event.newValue) {
                return;
            }
            this.handlePassportCallbackEvent($A.jsonParse(event.newValue));
        },

        onPassportWindowFocus() {
            this.checkPassportCallbackAfterReturn();
        },

        onPassportVisibilityChange() {
            if (!document.hidden) {
                this.checkPassportCallbackAfterReturn();
            }
        },

        checkPassportCallbackAfterReturn() {
            const callback = $A.jsonParse(window.localStorage.getItem('__project_passport_callback__'));
            this.handlePassportCallbackEvent(callback);
        },

        handlePassportCallbackEvent(callback) {
            if (this.qrcodeMode !== 'passport' || !this.qrcodeSessionId) {
                return;
            }
            if (callback?.sessionId !== this.qrcodeSessionId) {
                return;
            }
            this.qrcodeStatusText = '';
            this.qrcodeStatus();
        },

        openPassportAuthorize() {
            if (this.qrcodeMode !== 'passport' || !this.qrcodeUrlValue) {
                return;
            }
            window.open(this.qrcodeUrlValue, '_blank', 'noopener,noreferrer');
        },

        forgotPassword() {
            $A.modalWarning("请联系管理员！");
        },

        refreshCode() {
            if (this.codeLoad > 0) {
                return;
            }
            setTimeout(_ => {
                this.codeLoad++
            }, 600)
            this.$store.dispatch("call", {
                url: 'users/login/codejson',
            }).then(({data}) => {
                this.codeKey = data.key
                this.codeUrl = data.img
            }).catch(_ => {
                this.codeUrl = "error"
            }).finally(_ => {
                this.codeLoad--
            });
        },

        inputServerUrl() {
            if (this.privacyShow) {
                return
            }
            let value = $A.rightDelete(this.cacheServerUrl, "/api/");
            value = $A.leftDelete(value, "http://");
            if (!value && /^https?:/.test(window.location.protocol) && !/^localhost/.test(window.location.host)) {
                value = window.location.host
            }
            $A.modalInput({
                title: "使用 SSO 登录",
                value,
                placeholder: "请输入服务器地址",
                onOk: (value) => {
                    if (!value) {
                        return '请输入服务器地址'
                    }
                    return this.inputServerChack($A.trim(value))
                }
            });
        },

        inputServerChack(value) {
            return new Promise((resolve, reject) => {
                let url = value;
                if (!/\/api\/$/.test(url)) {
                    url = url + ($A.rightExists(url, "/") ? "api/" : "/api/");
                }
                if (!/^https?:\/\//i.test(url)) {
                    url = `https://${url}`;
                }
                this.$store.dispatch("call", {
                    url: `${url}system/setting`,
                    checkNetwork: false,
                    networkFailureRetry: false
                }).then(async ({data}) => {
                    if (typeof data.server_version === "undefined" && typeof data.all_group_mute === "undefined") {
                        reject(this.$L('服务器（(*)）版本过低', $A.getDomain(value)))
                    } else {
                        await this.setServerUrl(url)
                        resolve()
                    }
                }).catch(({ret, msg}) => {
                    if (ret === -1001) {
                        if (!/^https?:\/\//i.test(value)) {
                            this.inputServerChack(`http://${value}`).then(resolve).catch(reject);
                            return;
                        }
                        msg = this.$L('网络连接失败，请检查网络设置。');
                    }
                    reject(msg)
                });
            })
        },

        chackServerUrl(tip) {
            return new Promise((resolve, reject) => {
                if (this.isNotServer()) {
                    this.inputServerUrl()
                    tip === true && this.$nextTick(_ => $A.messageWarning("请输入服务器地址"))
                    reject()
                } else {
                    resolve()
                }
            })
        },

        async setServerUrl(value) {
            if (value == this.cacheServerUrl) {
                return
            }
            await $A.IDBSet("cacheServerUrl", value)
            $A.reloadUrl();
        },

        isNotServer() {
            let apiHome = $A.getDomain(window.systemInfo.apiUrl)
            return this.$isSoftware && (apiHome == "" || apiHome == "public")
        },

        onBlur() {
            if (this.loginType != 'login' || !this.email) {
                this.codeNeed = false;
                return;
            }
            this.loadIng++;
            this.$store.dispatch("call", {
                url: 'users/login/needcode',
                data: {
                    email: this.email,
                },
            }).then(() => {
                this.refreshCode()
                this.codeNeed = true
            }).catch(_ => {
                this.codeNeed = false
            }).finally(_ => {
                this.loadIng--
            });
        },

        onPrivacy(agree) {
            if (agree) {
                this.privacyShow = false
                this.chackServerUrl().catch(_ => {});
                $A.IDBSet("cachePrivacyShow", "no")
            } else {
                $A.eeuiAppGoDesktop()
            }
        },

        onLoginKeydown(e) {
            if (e.isComposing || e.key === 'Process' || e.keyCode === 229) return;
            if (e.keyCode === 13) {
                this.onLogin();
            }
        },

        async onWalletLogin() {
            if (this.walletLoading) return;
            this.walletLoading = true;
            try {
                const provider = await getProvider({preferYeYing: true, timeoutMs: 3000});
                if (!provider) throw new Error(this.$L('未检测到夜莺钱包，请先安装并解锁钱包插件'));
                let address = '';
                let walletProfile = {};
                try {
                    const profileResult = await connectAndGetWalletProfile({
                        provider,
                        fields: ['username', 'email'],
                    });
                    address = profileResult.address;
                    walletProfile = profileResult.profile || {};
                } catch (_) {
                    const accounts = await requestAccounts({provider});
                    address = accounts[0];
                }
                if (!address) throw new Error(this.$L('钱包未返回可用账号'));
                const challengeResponse = await fetch(`${window.location.origin}/api/public/auth/challenge`, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({address}),
                });
                const challengePayload = await challengeResponse.json();
                if (challengePayload.ret !== 1) throw new Error(challengePayload.msg || this.$L('获取钱包登录挑战失败'));
                const signature = await signMessage({provider, address, message: challengePayload.data.challenge});
                const verifyResponse = await fetch(`${window.location.origin}/api/public/auth/verify`, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({address, signature}),
                });
                const verifyPayload = await verifyResponse.json();
                if (verifyPayload.data?.code === 'wallet_email_required') {
                    await this.completeWalletEmail(verifyPayload.data.setup_token, walletProfile);
                    return;
                }
                if (verifyPayload.ret !== 1 || !verifyPayload.data?.token) throw new Error(verifyPayload.msg || this.$L('钱包登录失败'));
                const result = verifyPayload.data;
                const response = await fetch(`${window.location.origin}/api/users/info`, {
                    headers: {'dootask-token': result.token},
                });
                const payload = await response.json();
                if (!response.ok || !payload.data) {
                    throw new Error(this.$L('钱包登录成功，但用户信息获取失败'));
                }
                await this.$store.dispatch('handleClearCache', Object.assign({}, payload.data, {token: result.token}));
                this.goNext();
            } catch (error) {
                if (error?.message === 'Verify response missing token') {
                    $A.modalWarning('该钱包尚未绑定 YeYing 账号，请先使用邮箱账号登录后绑定钱包');
                } else {
                    $A.modalError(error?.message || '钱包登录失败');
                }
            } finally {
                this.walletLoading = false;
            }
        },

        completeWalletEmail(setupToken, walletProfile = {}) {
            return new Promise((resolve, reject) => {
                $A.modalInput({
                    title: '首次钱包登录，请设置邮箱',
                    value: walletProfile.email || '',
                    placeholder: '请输入邮箱地址',
                    onOk: (email) => {
                        email = $A.trim(email || '');
                        if (!$A.isEmail(email)) return '请输入有效邮箱地址';
                        return fetch(`${window.location.origin}/api/public/auth/email`, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                email,
                                username: walletProfile.username || '',
                                setup_token: setupToken,
                            }),
                        }).then(response => response.json()).then(payload => {
                            if (payload.ret !== 1) throw new Error(payload.msg || this.$L('邮箱设置失败'));
                            $A.modalWarning('验证邮件已发送，请完成邮箱验证后再使用钱包登录');
                            resolve();
                        });
                    },
                    onCancel: reject,
                });
            });
        },

        onLogin() {
            this.chackServerUrl(true).then(() => {
                this.email = $A.trim(this.email)
                this.password = $A.trim(this.password)
                this.password2 = $A.trim(this.password2)
                this.code = $A.trim(this.code)
                this.invite = $A.trim(this.invite)
                //
                if (this.loginType == 'reg' && !$A.isEmail(this.email)) {
                    $A.messageWarning("请输入正确的邮箱地址")
                    this.$refs.email.focus()
                    return
                }
                if (!this.email) {
                    $A.messageWarning("请输入帐号")
                    this.$refs.email.focus()
                    return
                }
                if (!this.password) {
                    $A.messageWarning("请输入密码")
                    this.$refs.password.focus()
                    return
                }
                if (this.loginType == 'reg') {
                    if (this.password != this.password2) {
                        $A.messageWarning("确认密码输入不一致")
                        this.$refs.password2.focus()
                        return
                    }
                }
                this.loadIng++
                this.$store.dispatch("call", {
                    url: 'users/login',
                    data: {
                        type: this.loginType,
                        email: this.email,
                        password: this.password,
                        code: this.code,
                        code_key: this.codeKey,
                        invite: this.invite,
                    },
                }).then(({data}) => {
                    $A.IDBSave("cacheLoginEmail", this.email)
                    this.codeNeed = false
                    //
                    this.loadIng++
                    this.$store.dispatch("handleClearCache", data)
                        .then(this.goNext)
                        .finally(_ => {
                            this.loadIng--
                        })
                }).catch(({data, msg}) => {
                    if (data.code === 'email') {
                        this.loginType = 'login'
                        $A.modalWarning(msg)
                    } else {
                        $A.modalError({
                            content: msg,
                            onOk: _ => {
                                this.$refs.code?.focus()
                            }
                        })
                    }
                    if (data.code === 'need') {
                        this.refreshCode()
                        this.codeNeed = true
                    }
                }).finally(_ => {
                    this.loadIng--
                })
            })
        },

        goNext() {
            this.loginJump = true
            const fromUrl = decodeURIComponent($A.getObject(this.$route.query, 'from'))
            if (fromUrl) {
                $A.IDBSet("clearCache", "login").then(_ => {
                    window.location.replace(fromUrl)
                })
            } else {
                this.goForward({name: 'manage-dashboard'}, true)
            }
        },

        onLanguage(l) {
            setLanguage(l)
        }
    }
}
</script>
