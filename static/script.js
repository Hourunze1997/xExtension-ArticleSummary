if (document.readyState && document.readyState !== 'loading') {
  configureSummarizeButtons();
} else {
  document.addEventListener('DOMContentLoaded', configureSummarizeButtons, false);
}

function configureSummarizeButtons() {
  document.getElementById('global').addEventListener('click', function (e) {
    for (var target = e.target; target && target != this; target = target.parentNode) {
      
      if (target.matches('.flux_header')) {
        target.nextElementSibling.querySelector('.oai-summary-btn').innerHTML = 'Summarize'
      }

      if (target.matches('.oai-summary-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) {
          summarizeButtonClick(target);
        }
        break;
      }
    }
  }, false);
}

function setOaiState(container, statusType, statusMsg, summaryText) {
  const button = container.querySelector('.oai-summary-btn');
  const content = container.querySelector('.oai-summary-content');
  // 根据 state 设置不同的状态
  if (statusType === 1) {
    container.classList.add('oai-loading');
    container.classList.remove('oai-error');
    content.innerHTML = statusMsg;
    button.disabled = true;
  } else if (statusType === 2) {
    container.classList.remove('oai-loading');
    container.classList.add('oai-error');
    content.innerHTML = statusMsg;
    button.disabled = false;
  } else {
    container.classList.remove('oai-loading');
    container.classList.remove('oai-error');
    if (statusMsg === 'finish'){
      button.disabled = false;
    }
  }

  console.log(content);
  
  if (summaryText) {
    content.innerHTML = summaryText.replace(/(?:\r\n|\r|\n)/g, '<br>');
  }
}

async function summarizeButtonClick(target) {
  var container = target.parentNode;
  if (container.classList.contains('oai-loading')) {
    return;
  }

  setOaiState(container, 1, '加载中', null);

  // 这是 php 获取参数的地址 - This is the address where PHP gets the parameters
  var url = target.dataset.request;
  var data = {
    ajax: true,
    _csrf: context.csrf
  };

  try {
    const response = await axios.post(url, data, {
      headers: {
        'Content-Type': 'application/json'
      }
    });

    const xresp = response.data;
    console.log(xresp);
    if (response.status !== 200 || !xresp.response || !xresp.response.data) {
      throw new Error('请求失败 / Request Failed. Status: ' + response.status +
          ', Response: ' + JSON.stringify(xresp.response));

    }

    if (xresp.response.error) {
      setOaiState(container, 2, xresp.response.data, null);
    } else {
      // 解析 PHP 返回的参数
      const oaiParams = xresp.response.data;
      await sendOpenAIRequest(container, oaiParams);
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, '请求失败 / Request Failed', null);
  }
}

async function sendOpenAIRequest(container, oaiParams) {
  try {
    let body = JSON.parse(JSON.stringify(oaiParams));
    delete body['oai_url'];
    delete body['oai_key'];
    const response = await fetch(oaiParams.oai_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${oaiParams.oai_key}`
      },
      body: JSON.stringify(body)
    });

    if (!response.ok) {
      throw new Error('请求失败 / Request Failed');
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let accumulatedData = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        try {
          console.log('Final Raw chunk response:', accumulatedData);
          const parsedData = JSON.parse(accumulatedData);  // 这里解析完整 JSON
          const text = parsedData?.choices[0]?.message?.content || '';

          setOaiState(container, 0, null, marked.parse(text));  // 显示结果
        } catch (error) {
          console.error('JSON 解析错误:', error, '收到的数据:', accumulatedData);
          setOaiState(container, 2, '数据解析失败', null);
        }
        break;
      }

      const chunk = decoder.decode(value, { stream: true });
      console.log('Received chunk:', chunk);
      accumulatedData += chunk;  // 累积 chunk
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, '请求失败 / Request Failed', null);
  }
}


async function sendOllamaRequest(container, oaiParams){
  try {
    const response = await fetch(oaiParams.oai_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${oaiParams.oai_key}`
      },
      body: JSON.stringify(oaiParams)
    });

    if (!response.ok) {
      throw new Error('请求失败 / Request Failed');
    }
  
    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let text = '';
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        setOaiState(container, 0, 'finish', null);
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      // Try to process complete JSON objects from the buffer
      let endIndex;
      while ((endIndex = buffer.indexOf('\n')) !== -1) {
        const jsonString = buffer.slice(0, endIndex).trim();
        try {
          if (jsonString) {
            const json = JSON.parse(jsonString);
            text += json.response
            setOaiState(container, 0, null, marked.parse(text));
          }
        } catch (e) {
          // If JSON parsing fails, output the error and keep the chunk for future attempts
          console.error('Error parsing JSON:', e, 'Chunk:', jsonString);
        }
        // Remove the processed part from the buffer
        buffer = buffer.slice(endIndex + 1); // +1 to remove the newline character
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, '请求失败 / Request Failed', null);
  }
}
