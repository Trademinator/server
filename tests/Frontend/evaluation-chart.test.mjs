import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluationOutcomeSegments } from '../../resources/js/components/evaluation-chart.js';

test('plots observed close-to-close movements across exactly H candles', () => {
    const candles = [100, 102, 98, 103, 110].map((close, index) => ({ time: index + 1, close }));
    const predictions = [{ time: 1, supported: true, predicted: 'bull', actual: 'bear', confidence: 0.8 }];
    const segments = evaluationOutcomeSegments(candles, predictions, 3);
    assert.deepEqual(segments[0].points, [{ time: 1, value: 100 }, { time: 4, value: 103 }]);
    assert.equal(segments[0].predicted, 'bull');
    assert.equal(evaluationOutcomeSegments(candles.slice(0, 3), predictions, 3).length, 0);
    assert.equal(evaluationOutcomeSegments(candles, predictions, 0).length, 0);
});

test('unsupported outcomes and unknown predictions do not generate lines', () => {
    const candles = [{time:1,close:1},{time:2,close:2}];
    assert.equal(evaluationOutcomeSegments(candles,[{time:1,predicted:'bull',supported:false}],1).length,0);
    assert.equal(evaluationOutcomeSegments(candles,[{time:1,predicted:'other',supported:true}],1).length,0);
});
