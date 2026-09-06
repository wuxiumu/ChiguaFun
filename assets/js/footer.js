/**
 * OpenNana 提示词库 - 全站页脚
 *
 * 由 JS 注入：导航项点击弹窗展示正文，文案/链接全部写在本文件 CFG，改这里即可。
 * 页面只需保留 <footer class="site-foot">（可无内容）；没有也会自动创建。
 */
(function () {
    'use strict';

    // ---------------------- 可改配置（只动这里） ----------------------
    var CFG = {
        // 页脚导航：id 对应 modals 键
        links: [
            { id: 'friends',  text: '友情链接' },
            { id: 'contact',  text: '联系我们' },
            { id: 'terms',    text: '服务协议' },
            { id: 'privacy',  text: '隐私政策' },
            { id: 'disclaimer', text: '免责声明' }
        ],

        // 弹窗正文：paragraphs 为段落；links 为可选外链列表（友情链接用）
        modals: {
            friends: {
                title: '友情链接',
                paragraphs: [
                    '欢迎优质站点互换友情链接。以下为吃瓜神探相关站点与合作展示（排名不分先后）：'
                ],
                links: [
                    { name: '吃瓜神探官网', url: 'https://chiguashentan.com/' },
                    { name: '51chigua 吃瓜提示词库', url: 'https://banana.chiguashentan.com/' }
                ],
                paragraphsAfter: [
                    '申请友链请通过「联系我们」留言，需提供站点名称、URL 与简介。我们保留对友链的审核与调整权利。'
                ]
            },
            contact: {
                title: '联系我们',
                paragraphs: [
                    '感谢关注「吃瓜神探 / 51chigua 吃瓜提示词库」。如有合作、纠错、内容下架或友链申请，可通过以下方式联系：',
                    '官网：https://chiguashentan.com/',
                    '提示词库：https://banana.chiguashentan.com/',
                    '备案主体：上海吃瓜神探数字传媒科技有限公司',
                    '我们会在工作日内尽快回复；涉及侵权投诉请注明页面链接、权属证明与联系方式，以便核实处理。'
                ]
            },
            terms: {
                title: '服务协议',
                paragraphs: [
                    '欢迎使用本提示词库（下称「本服务」）。访问或使用本站即表示您已阅读并同意本协议。若不同意，请停止使用。',
                    '一、服务说明\n本站提供 AI 提示词及生成案例的浏览、搜索、复制与分享等功能，内容来源于公开整理与用户/第三方贡献，仅供学习与参考。',
                    '二、账号与使用规范\n您应合法、善意使用本服务，不得利用本站从事违法违规、侵权、传播恶意软件或干扰服务正常运行的行为。',
                    '三、内容与知识产权\n提示词、图片、文案等可能涉及第三方权利。本站展示不代表主张相关知识产权；商业使用前请自行确认授权。未经许可，不得批量抓取、镜像或倒卖本站数据。',
                    '四、服务变更\n我们可能根据运营需要调整、中断或终止部分或全部服务，并尽量提前公告，但不因此承担额外责任。',
                    '五、协议更新\n本协议可能适时修订，修订后以本页展示为准。继续使用即视为接受更新后的条款。'
                ]
            },
            privacy: {
                title: '隐私政策',
                paragraphs: [
                    '我们重视您的隐私。本政策说明本站如何收集、使用与保护相关信息。',
                    '一、收集的信息\n为保障服务与统计需要，我们可能收集：访问日志（如 IP、时间、页面路径）、设备与浏览器类型、Cookie / 本地存储（如主题偏好），以及您主动提交的联系内容。',
                    '二、使用目的\n用于站点访问统计、性能与安全防护、故障排查，以及回应您的咨询或投诉。我们不会出售您的个人信息。',
                    '三、第三方服务\n本站可能接入统计分析（如 Microsoft Clarity、51.la 等）。上述服务有其独立隐私政策，请以对方说明为准。',
                    '四、信息安全与存储\n我们采取合理技术与管理措施保护数据，但互联网传输无法保证绝对安全。日志类数据通常按运营需要保留有限期限。',
                    '五、您的权利\n如需查询、更正或删除与您相关的反馈信息，请通过「联系我们」提出。涉及未成年人信息，请监护人代为联系处理。',
                    '六、政策更新\n本政策更新后将在本弹窗公布，重大变更时我们会尽量以站内提示等方式告知。'
                ]
            },
            disclaimer: {
                title: '免责声明',
                paragraphs: [
                    '本提示词库所展示的提示词、图片、视频及案例内容，多为 AI 生成或公开来源整理，仅供学习、研究与交流参考，不构成任何专业建议。',
                    '一、内容准确性\nAI 生成结果具有不确定性，本站不对内容的合法性、准确性、完整性、时效性作保证。请您自行判断并承担使用风险。',
                    '二、第三方权利\n若您认为本站内容侵犯您的合法权益，请通过「联系我们」提交材料，我们将在核实后尽快处理（含删除或断开链接）。',
                    '三、外链与跳转\n本站可能包含指向第三方网站的链接。第三方页面由其自行运营，本站不对其内容与隐私做法负责。',
                    '四、责任限制\n在法律允许的范围内，因使用或无法使用本服务而导致的任何直接或间接损失，本站及关联方不承担责任。',
                    '五、其他\n本声明与服务协议、隐私政策一并适用。如有冲突，以更有利于保护用户合法权益且符合现行法律的解释为准。'
                ]
            }
        },

        copyright: '© 2024-2026 吃瓜神探 · chiguashentan.com',
        copyrightHref: 'https://chiguashentan.com/',
        beian: '沪ICP备2024062770号-1',
        beianHref: 'https://beian.miit.gov.cn/'
    };
    // ----------------------------------------------------------------

    var modalEl = null;
    var titleEl = null;
    var bodyEl = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function linkHtml(text, href, external) {
        if (!href) return '<span>' + esc(text) + '</span>';
        var extra = external ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' + esc(href) + '"' + extra + '>' + esc(text) + '</a>';
    }

    function paraHtml(text) {
        return String(text || '').split('\n').map(function (line) {
            return '<p>' + esc(line) + '</p>';
        }).join('');
    }

    function renderModalBody(page) {
        var html = '';
        (page.paragraphs || []).forEach(function (p) { html += paraHtml(p); });
        if (page.links && page.links.length) {
            html += '<ul class="foot-modal-links">';
            page.links.forEach(function (it) {
                html += '<li>' + linkHtml(it.name, it.url, true) + '</li>';
            });
            html += '</ul>';
        }
        (page.paragraphsAfter || []).forEach(function (p) { html += paraHtml(p); });
        return html;
    }

    function ensureModal() {
        if (modalEl) return;
        modalEl = document.createElement('div');
        modalEl.className = 'foot-modal';
        modalEl.setAttribute('role', 'dialog');
        modalEl.setAttribute('aria-modal', 'true');
        modalEl.innerHTML =
            '<div class="foot-modal-box">' +
                '<div class="foot-modal-head">' +
                    '<h2 class="foot-modal-title"></h2>' +
                    '<button type="button" class="foot-modal-close" aria-label="关闭">×</button>' +
                '</div>' +
                '<div class="foot-modal-body"></div>' +
            '</div>';
        document.body.appendChild(modalEl);
        titleEl = modalEl.querySelector('.foot-modal-title');
        bodyEl = modalEl.querySelector('.foot-modal-body');
        modalEl.querySelector('.foot-modal-close').addEventListener('click', closeModal);
        modalEl.addEventListener('click', function (e) {
            if (e.target === modalEl) closeModal();
        });
    }

    function openModal(id) {
        var page = CFG.modals[id];
        if (!page) return;
        ensureModal();
        titleEl.textContent = page.title || '';
        bodyEl.innerHTML = renderModalBody(page);
        modalEl.classList.add('on');
        document.body.style.overflow = 'hidden';
        modalEl.setAttribute('aria-label', page.title || '说明');
    }

    function closeModal() {
        if (!modalEl) return;
        modalEl.classList.remove('on');
        document.body.style.overflow = '';
    }

    function ensureFooter() {
        var foot = document.querySelector('footer.site-foot');
        if (!foot) {
            foot = document.createElement('footer');
            foot.className = 'site-foot';
            var toast = document.getElementById('toast');
            var wrap = document.querySelector('.wrap');
            if (toast && toast.parentNode) {
                toast.parentNode.insertBefore(foot, toast);
            } else if (wrap && wrap.parentNode) {
                wrap.parentNode.insertBefore(foot, wrap.nextSibling);
            } else {
                document.body.appendChild(foot);
            }
        }

        var inner = foot.querySelector('.inner');
        if (!inner) {
            inner = document.createElement('div');
            inner.className = 'inner';
            foot.appendChild(inner);
        }

        var prevStats = '';
        var oldStats = inner.querySelector('#traffic-stats');
        if (oldStats) prevStats = oldStats.innerHTML;

        var stats = document.createElement('span');
        stats.className = 'foot-stats';
        stats.id = 'traffic-stats';
        if (prevStats) stats.innerHTML = prevStats;

        var nav = document.createElement('nav');
        nav.className = 'foot-nav';
        nav.setAttribute('aria-label', '页脚导航');
        CFG.links.forEach(function (it, i) {
            if (i > 0) {
                var sep = document.createElement('span');
                sep.className = 'foot-sep';
                sep.setAttribute('aria-hidden', 'true');
                sep.textContent = '·';
                nav.appendChild(sep);
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'foot-nav-btn';
            btn.textContent = it.text;
            btn.setAttribute('data-foot-modal', it.id);
            btn.addEventListener('click', function () { openModal(it.id); });
            nav.appendChild(btn);
        });

        var copy = document.createElement('p');
        copy.className = 'foot-copy';
        copy.innerHTML = linkHtml(CFG.copyright, CFG.copyrightHref, true);

        var beian = document.createElement('p');
        beian.className = 'foot-beian';
        beian.innerHTML = linkHtml(CFG.beian, CFG.beianHref, true);

        inner.innerHTML = '';
        inner.appendChild(stats);
        inner.appendChild(nav);
        inner.appendChild(copy);
        inner.appendChild(beian);
    }

    function boot() {
        if (!document.body) return;
        ensureFooter();
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalEl && modalEl.classList.contains('on')) {
                closeModal();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
