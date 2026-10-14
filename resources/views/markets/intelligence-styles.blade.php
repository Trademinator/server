@once
<style>
    .pair-review .intelligence-progress { margin: 1rem 0; }
    .pair-review .intelligence-progress-label { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .3rem 1rem; margin-bottom: .4rem; font-size: .9rem; }
    .pair-review .intelligence-signal-mode { display: block; margin-top: .2rem; font-size: .72rem; font-weight: 500; line-height: 1.25; opacity: .72; }
    /* Scoped Bootstrap 5.3 progress layout, alongside the existing custom page styles. */
    .pair-review .progress { display: flex; height: .65rem; overflow: hidden; background: #e2e8f0; border-radius: .4rem; }
    .pair-review .progress-bar { display: flex; flex-direction: column; justify-content: center; overflow: hidden; color: white; text-align: center; white-space: nowrap; background: #2563eb; }
    .pair-review .progress-bar.bg-success { background: #15803d; }
    .pair-review .intelligence-native-progress { display: block; width: 100%; height: .65rem; appearance: none; border: 0; border-radius: .4rem; overflow: hidden; background: #e2e8f0; accent-color: #2563eb; }
    .pair-review .intelligence-native-progress::-webkit-progress-bar { background: #e2e8f0; border-radius: .4rem; }
    .pair-review .intelligence-native-progress::-webkit-progress-value { background: #2563eb; border-radius: .4rem; }
    .pair-review .intelligence-native-progress::-moz-progress-bar { background: #2563eb; border-radius: .4rem; }
    .pair-review .intelligence-issues { list-style: disc; padding-left: 1.3rem; margin: 1rem 0; }
    .pair-review .intelligence-issues li { margin: .4rem 0; }
    .pair-review .intelligence-command { white-space: pre-wrap; overflow-wrap: anywhere; padding: .8rem; background: #f1f5f9; color: #172033; border: 1px solid #94a3b8; border-radius: .5rem; }
    .pair-review .intelligence-command code { color: inherit; background: transparent; }
    .dark .pair-review .intelligence-command { background: #24334b; color: #f1f5f9; border-color: #64748b; }
    .pair-review .intelligence-help { display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; width: 1.75rem; height: 1.75rem; margin-left: .3rem; border: 1px solid #64748b; border-radius: 50%; background: #edf3fb; color: #172033; font-size: .85rem; font-weight: 700; cursor: help; }
    .dark .pair-review .intelligence-help { background: #24334b; color: #f1f5f9; border-color: #94a3b8; }
    .intelligence-tooltip { z-index: 1080; max-width: min(22rem, calc(100vw - 2rem)); font-family: ui-sans-serif, system-ui, sans-serif; font-size: .9rem; font-weight: 400; line-height: 1.5; text-align: left; }
    .intelligence-tooltip .tooltip-inner { padding: .8rem 1rem; border: 1px solid #94a3b8; border-radius: .5rem; background: #172033; color: #fff; box-shadow: 0 4px 16px #0003; }
    .pair-review details { margin: 1rem 0; }
    .pair-review summary { cursor: pointer; font-weight: 600; }
    .pair-review h3 { margin: 1.5rem 0 .6rem; font-size: 1.05rem; font-weight: 700; }
    .dashboard-exchange-logo { display: inline-flex; height: 24px; flex: 0 0 auto; align-items: center; justify-content: center; font-size: .75rem; }
    .dashboard-exchange-logo img { display: block; width: auto; height: 24px; max-width: none; }
    .dashboard-exchange-logo img + span { display: none; }
    .dashboard-command { white-space: pre-wrap; overflow-wrap: anywhere; max-width: 100%; padding: .65rem; margin-top: .5rem; border-radius: .4rem; background: #e8eef3; color: #172c43; font-size: .8rem; }
    .dark .dashboard-command { background: #172c43; color: #e5edf5; }
</style>
@endonce
